<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\Tenants\Models\TenantProfile;

/*
 * P03-03a sub-AC "two concurrent activations on one unit leave exactly one
 * active lease": several PHP processes, each with its own database
 * connection, try at the same moment to activate every draft lease of a
 * few units (three tenants waiting for each unit), each process in its
 * own order. LeaseService locks the unit row (UnitDirectory::lock) before
 * the lease, so the activations of one unit wait for each other and only
 * the first finds the unit free. Drill: the processes see only committed
 * rows, so this test commits and cleans up after itself.
 */

const LEASE_DRILL_TABLES = ['jobs', 'lease_contracts', 'document_verifications', 'lease_deposit_entries', 'leases', 'units', 'properties', 'tenant_profiles', 'document_counters', 'organizations', 'users', 'activity_log'];

beforeEach(function () {
    // DatabaseTruncation empties every table between drills, the audit
    // chain's head row too (the migration seeds it).
    DB::table('audit_chain_head')->insertOrIgnore(['id' => 1, 'last_hash' => null, 'updated_at' => now()]);
    // Permissions are LeasePolicy's tests; this drill is about the locks.
    Gate::before(fn (): bool => true);

    $this->organization = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd']);
    $this->user = User::factory()->create();
});

afterEach(function () {
    app(OrganizationContext::class)->clear();

    foreach (LEASE_DRILL_TABLES as $table) {
        DB::table($table)->delete();
    }

    DB::table('audit_chain_head')->update(['last_hash' => null]);
});

/** Starts tests/Fixtures/Processes/lease-activations.php against this test's database. */
function leaseActivatingProcess(Organization $organization, User $user, string $barrier, int $seed, array $leaseIds): InvokedProcess
{
    $database = DB::connection();

    return Process::path(base_path())->timeout(120)->env([
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => $database->getName(),
        'DB_HOST' => (string) $database->getConfig('host'),
        'DB_PORT' => (string) $database->getConfig('port'),
        'DB_DATABASE' => $database->getDatabaseName(),
        'DB_USERNAME' => (string) $database->getConfig('username'),
        'DB_PASSWORD' => (string) $database->getConfig('password'),
        // Each activation issues its contract (P03-03b); its PDF job waits in the jobs table instead of rendering here.
        'QUEUE_CONNECTION' => 'database',
    ])->start([PHP_BINARY, 'tests/Fixtures/Processes/lease-activations.php', (string) $organization->id, (string) $user->id, $barrier, (string) $seed, implode(',', $leaseIds)]);
}

it('sub-AC: leaves exactly one active lease per unit when parallel processes activate drafts of the same units at once', function () {
    $processes = 4;
    $units = 3;
    $draftsPerUnit = 3;

    // Committed set-up: units, tenants and their drafts (made through LeaseService).
    $drafts = app(OrganizationContext::class)->run($this->organization, function () use ($units, $draftsPerUnit): array {
        $building = Property::factory()->create(['name' => 'Palm Court']);
        $ids = [];

        foreach (range(1, $units) as $u) {
            $unit = Unit::factory()->in($building)->create(['unit_label' => "Flat {$u}"]);

            foreach (range(1, $draftsPerUnit) as $d) {
                $ids[] = app(LeaseService::class)->draft($this->user, [
                    'tenantId' => TenantProfile::factory()->create()->id,
                    'unitId' => $unit->id,
                    'billingCycle' => 'annual',
                    'startDate' => '2027-01-01',
                    'endDate' => '2027-12-31',
                    'rent' => '1,500,000',
                    'deposit' => '150,000',
                ])->id;
            }
        }

        return $ids;
    });

    $barrier = sys_get_temp_dir().'/goproperty-leases-'.Str::random(10);
    File::ensureDirectoryExists($barrier);

    try {
        $running = [];

        for ($p = 0; $p < $processes; $p++) {
            $running[] = leaseActivatingProcess($this->organization, $this->user, $barrier, 1000 + $p, $drafts);
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

    $activated = array_values(array_filter($lines, fn (array $line): bool => $line['outcome'] === 'activated'));
    $errors = array_values(array_filter($lines, fn (array $line): bool => $line['outcome'] === 'error'));

    // Every process tried every draft, side by side.
    expect($lines)->toHaveCount($processes * count($drafts))
        ->and(array_unique(array_column($lines, 'pid')))->toHaveCount($processes)
        ->and($errors)->toBe([]);

    $pids = array_unique(array_column($lines, 'pid'));
    $starts = array_map(fn (int $pid): float => min(array_column(array_filter($lines, fn (array $line): bool => $line['pid'] === $pid), 'started')), $pids);
    $ends = array_map(fn (int $pid): float => max(array_column(array_filter($lines, fn (array $line): bool => $line['pid'] === $pid), 'ended')), $pids);
    expect(max($starts))->toBeLessThan(min($ends));

    // Exactly one activation won per unit; every other try was refused.
    expect($activated)->toHaveCount($units)
        ->and(array_unique(array_column($activated, 'unit')))->toHaveCount($units);

    [$leases, $unitStatuses, $deposits, $counter, $contracts] = app(OrganizationContext::class)->run($this->organization, fn (): array => [
        Lease::query()->get(),
        Unit::query()->pluck('status')->map(fn ($status): string => $status->value)->all(),
        DB::table('lease_deposit_entries')->where('organization_id', $this->organization->id)->count(),
        (int) DB::table('document_counters')->where('organization_id', $this->organization->id)->value('last_seq'),
        DB::table('lease_contracts')->where('organization_id', $this->organization->id)->pluck('contract_number', 'lease_id')->all(),
    ]);

    $active = $leases->where('status.value', 'active');
    expect($active)->toHaveCount($units)
        ->and($active->pluck('unit_id')->unique())->toHaveCount($units)
        ->and($leases->where('status.value', 'draft'))->toHaveCount(count($drafts) - $units)
        ->and($unitStatuses)->toBe(array_fill(0, $units, 'occupied'))
        // One deposit taken per activation, contract numbers 1..N with no gap.
        ->and($deposits)->toBe($units)
        ->and($counter)->toBe($units)
        // One issued contract per activation (P03-03b), with the lease's number.
        ->and($contracts)->toEqual($active->pluck('contract_number', 'id')->all());

    $sequences = $active->pluck('contract_number')->map(fn (string $number): int => (int) Str::afterLast($number, '-'))->sort()->values()->all();
    expect($sequences)->toBe(range(1, $units))
        ->and(array_column($activated, 'contract'))->each->toMatch('/^LS-ADR-\d{4}-\d{6}$/');
});
