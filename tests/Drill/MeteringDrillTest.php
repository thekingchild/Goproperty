<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Marketing\PlanCatalogue;
use App\Models\Organization;
use App\Subscriptions\Events\SubscriptionStatusChanged;
use App\Subscriptions\SubscriptionService;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\SubscriptionUnitEvent;

/*
 * P06-02 acceptance "rerunning metering creates one invoice per org per
 * period", under concurrency (BILLING_PAYMENTS.md § 3; AGENT.md § 3 rule
 * 6): several PHP processes, each with its own database connection, run
 * metering for the same subscription at the same moment, directly and
 * through the hourly job. Exactly one invoice, one ledger row and one
 * invoice number come out. Drill: the processes see only committed rows,
 * so this test commits and cleans up after itself.
 */

const METERING_DRILL_TABLES = ['subscription_unit_events', 'platform_invoices', 'platform_invoice_counters', 'subscriptions', 'units', 'properties', 'activity_log', 'organizations'];

beforeEach(function () {
    // DatabaseTruncation empties every table between drills: the audit
    // chain's head row and the launch plans (their migration skips plans
    // that exist) come back.
    DB::table('audit_chain_head')->insertOrIgnore(['id' => 1, 'last_hash' => null, 'updated_at' => now()]);
    (require database_path('migrations/2026_12_04_060106_seed_launch_plans.php'))->up();
    app(PlanCatalogue::class)->flush();

    $this->organization = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd', 'status' => OrganizationStatus::Active]);

    app(OrganizationContext::class)->run($this->organization, function (): void {
        $building = Property::factory()->create();
        Unit::factory()->in($building)->count(30)->create();

        // A trial that ended an hour ago: active, its first period not yet invoiced (the listener is held back).
        Event::fake([SubscriptionStatusChanged::class]);
        $subscriptions = app(SubscriptionService::class);
        $subscriptions->start(null, app(PlanCatalogue::class)->find('starter') ?? throw new LogicException('No Starter plan.'), CarbonImmutable::now()->subHour());
        $subscriptions->endTrial();
    });
});

afterEach(function () {
    app(OrganizationContext::class)->clear();

    foreach (METERING_DRILL_TABLES as $table) {
        DB::table($table)->delete();
    }

    DB::table('audit_chain_head')->update(['last_hash' => null]);
});

/** Starts tests/Fixtures/Processes/metering-runs.php against this test's database. */
function meteringProcess(Organization $organization, int $runs, string $barrier): InvokedProcess
{
    $database = DB::connection();

    return Process::path(base_path())->timeout(120)->env([
        'APP_ENV' => 'testing',
        'QUEUE_CONNECTION' => 'sync',
        'CACHE_STORE' => 'array',
        'MAIL_MAILER' => 'array',
        'LOG_CHANNEL' => 'null',
        'DB_CONNECTION' => $database->getName(),
        'DB_HOST' => (string) $database->getConfig('host'),
        'DB_PORT' => (string) $database->getConfig('port'),
        'DB_DATABASE' => $database->getDatabaseName(),
        'DB_USERNAME' => (string) $database->getConfig('username'),
        'DB_PASSWORD' => (string) $database->getConfig('password'),
    ])->start([PHP_BINARY, 'tests/Fixtures/Processes/metering-runs.php', (string) $organization->id, (string) $runs, $barrier]);
}

it('acceptance: parallel metering runs make exactly one invoice for the period', function () {
    $processes = 5;
    $runs = 6;
    $barrier = sys_get_temp_dir().'/goproperty-metering-'.Str::random(10);
    File::ensureDirectoryExists($barrier);

    try {
        $running = [];

        for ($i = 0; $i < $processes; $i++) {
            $running[] = meteringProcess($this->organization, $runs, $barrier);
        }

        $deadline = microtime(true) + 90;

        while (count(File::glob($barrier.'/ready-*')) < $processes) {
            foreach ($running as $process) {
                expect($process->running() || $process->latestErrorOutput() === '')->toBeTrue($process->latestErrorOutput());
            }

            expect(microtime(true))->toBeLessThan($deadline, 'The processes did not boot in time.');
            usleep(10_000);
        }

        File::put($barrier.'/go', '');
        $lines = [];

        foreach ($running as $process) {
            $result = $process->wait();
            expect($result->successful())->toBeTrue($result->output().$result->errorOutput());

            foreach (array_filter(explode(PHP_EOL, $result->output())) as $line) {
                $lines[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }
    } finally {
        File::deleteDirectory($barrier);
    }

    $errors = array_values(array_filter($lines, fn (array $line): bool => isset($line['error'])));
    expect($errors)->toBe([])
        ->and($lines)->toHaveCount($processes * $runs);

    // The processes really ran side by side: each started before any ended.
    $pids = array_unique(array_column($lines, 'pid'));
    $starts = array_map(fn (int $pid): float => min(array_column(array_filter($lines, fn (array $line): bool => $line['pid'] === $pid), 'started')), $pids);
    $ends = array_map(fn (int $pid): float => max(array_column(array_filter($lines, fn (array $line): bool => $line['pid'] === $pid), 'ended')), $pids);
    expect(max($starts))->toBeLessThan(min($ends));

    app(OrganizationContext::class)->run($this->organization, function (): void {
        $invoices = PlatformInvoice::query()->get();
        $year = CarbonImmutable::now('Africa/Lagos')->format('Y');

        expect($invoices)->toHaveCount(1)
            ->and($invoices->sole()->number)->toBe("PR-{$year}-000001")
            ->and($invoices->sole()->units_billed)->toBe(30)
            ->and(SubscriptionUnitEvent::query()->count())->toBe(1)
            ->and((int) DB::table('platform_invoice_counters')->where('year', (int) $year)->value('last_seq'))->toBe(1);
    });
});
