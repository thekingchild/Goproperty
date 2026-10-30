<?php

declare(strict_types=1);

use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\SmsCreditBalance;
use App\Models\SmsCreditEntry;
use App\Settings\SettingsStore;
use App\Sms\Credits\CreditReason;
use App\Sms\Credits\SmsCredits;
use App\Sms\SmsOutbox;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * P05-03 acceptance "sending stops at zero credit", under concurrency
 * (docs/plans/P05-subtasks.md "Defaults taken in P05-03": parallel sends
 * never take the balance below zero): several PHP processes, each with
 * its own database connection, send their own queued SMS through
 * App\Sms\SendSms at the same time, all against one organization's 25
 * credits. Exactly 25 go, the rest are `no_credit`, the balance ends at
 * zero and equals the ledger. Drill: the processes see only committed
 * rows, so this test commits and cleans up after itself.
 */

const SMS_DRILL_TABLES = ['sms_credit_entries', 'sms_credit_balances', 'notification_deliveries', 'settings', 'jobs', 'organizations', 'activity_log'];

beforeEach(function () {
    // DatabaseTruncation empties every table between drills, the audit
    // chain's head row too (the migration seeds it).
    DB::table('audit_chain_head')->insertOrIgnore(['id' => 1, 'last_hash' => null, 'updated_at' => now()]);
    $this->organization = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd', 'signup_plan_key' => 'starter']);
    app(SettingsStore::class)->set('sms_credits.monthly_allowance', 25, $this->organization);
    app(OrganizationContext::class)->run($this->organization, fn () => app(SmsCredits::class)->refreshAllowance());
});

afterEach(function () {
    app(OrganizationContext::class)->clear();

    foreach (SMS_DRILL_TABLES as $table) {
        DB::table($table)->delete();
    }

    DB::table('audit_chain_head')->update(['last_hash' => null]);
});

/** Starts tests/Fixtures/Processes/sms-sends.php against this test's database. */
function smsSendingProcess(Organization $organization, array $deliveryIds, string $barrier): InvokedProcess
{
    $database = DB::connection();

    return Process::path(base_path())->timeout(120)->env([
        'APP_ENV' => 'testing',
        'QUEUE_CONNECTION' => 'sync',
        'CACHE_STORE' => 'array',
        'LOG_CHANNEL' => 'null',
        'DB_CONNECTION' => $database->getName(),
        'DB_HOST' => (string) $database->getConfig('host'),
        'DB_PORT' => (string) $database->getConfig('port'),
        'DB_DATABASE' => $database->getDatabaseName(),
        'DB_USERNAME' => (string) $database->getConfig('username'),
        'DB_PASSWORD' => (string) $database->getConfig('password'),
    ])->start([PHP_BINARY, 'tests/Fixtures/Processes/sms-sends.php', (string) $organization->id, implode(',', $deliveryIds), $barrier]);
}

it('acceptance: parallel senders never take the SMS credits below zero, and the ledger balances', function () {
    $processes = 5;
    $each = 12;
    Queue::fake();
    $deliveries = app(OrganizationContext::class)->run($this->organization, fn (): array => array_map(
        fn (int $n): int => app(SmsOutbox::class)->queue('reminder.rent_due', '+234803'.str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'Adeyemi Realty: rent of NGN 1,500,000 is due tomorrow.', 'drill:'.$n)->id,
        range(1, $processes * $each),
    ));
    $barrier = sys_get_temp_dir().'/goproperty-sms-credits-'.Str::random(10);
    File::ensureDirectoryExists($barrier);

    try {
        $running = [];

        foreach (array_chunk($deliveries, $each) as $mine) {
            $running[] = smsSendingProcess($this->organization, $mine, $barrier);
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
        ->and($lines)->toHaveCount($processes * $each);

    // The processes really ran side by side: each started before any ended.
    $pids = array_unique(array_column($lines, 'pid'));
    $starts = array_map(fn (int $pid): float => min(array_column(array_filter($lines, fn (array $line): bool => $line['pid'] === $pid), 'started')), $pids);
    $ends = array_map(fn (int $pid): float => max(array_column(array_filter($lines, fn (array $line): bool => $line['pid'] === $pid), 'ended')), $pids);
    expect(max($starts))->toBeLessThan(min($ends));

    app(OrganizationContext::class)->run($this->organization, function () use ($processes, $each): void {
        $statuses = NotificationDelivery::query()->pluck('status')->countBy(fn ($status): string => $status->value)->all();
        ksort($statuses);
        $balance = SmsCreditBalance::query()->sole();
        $usage = SmsCreditEntry::query()->where('reason', CreditReason::Usage)->get();

        // Exactly the 25 credits' worth went (delivered: the log driver's status check ran inline).
        expect($statuses)->toBe(['delivered' => 25, 'no_credit' => $processes * $each - 25])
            ->and($balance->total())->toBe(0)
            ->and((int) SmsCreditEntry::query()->sum('credits'))->toBe(0)
            ->and(SmsCreditEntry::query()->min('balance_after'))->toBe(0)
            ->and($usage)->toHaveCount(25)
            ->and($usage->pluck('notification_delivery_id')->unique())->toHaveCount(25)
            // Each charged delivery is one that went; none that did not was charged.
            ->and(NotificationDelivery::query()->whereIn('id', $usage->pluck('notification_delivery_id'))->where('status', 'delivered')->count())->toBe(25)
            // The running balance went down one credit at a time: 24 … 0, each once.
            ->and($usage->pluck('balance_after')->sort()->values()->all())->toBe(range(0, 24));
    });
});
