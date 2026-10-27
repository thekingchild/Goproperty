<?php

declare(strict_types=1);

use App\Documents\BrandingSnapshots;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Billing\Bills\BillDraft;
use Modules\Billing\Bills\BillIssuer;
use Modules\Billing\Bills\BillLine;
use Modules\Billing\Bills\RentBillGenerator;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Contracts\BillNotPayable;
use Modules\Billing\Enums\BillableType;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Jobs\RenderInvoice;
use Modules\Billing\Models\Bill;
use Modules\Leasing\Contracts\LeaseBillingSchedule;
use Modules\Leasing\Jobs\RenderLeaseContract;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\Tenants\Models\TenantProfile;

/*
 * P03-04 acceptance with real concurrency: "rerunning the job creates no
 * duplicate bills" also when runs overlap, and "numbers are gap-free under
 * concurrency" at the level of the bill generation (P03-04a's drill covers
 * the numberer itself). Several PHP processes, each with its own database
 * connection, generate the same organization's rent bills at the same
 * moment, without the job's cache lock: the lease row lock
 * (LeaseBillingSchedule::lock) and the unique natural key
 * (lease_id, type, period_start) are what keep them apart. Also the bill
 * ledger under concurrent payments (designed for P03-10a), and its refusal
 * to work outside a transaction. Drill: the processes see only committed
 * rows, so this test commits and cleans up after itself.
 */

const BILL_DRILL_TABLES = ['bill_items', 'bills', 'document_verifications', 'lease_contracts', 'lease_deposit_entries', 'leases', 'units', 'properties', 'tenant_profiles', 'document_counters', 'settings', 'organizations', 'users', 'activity_log'];

beforeEach(function () {
    DB::table('audit_chain_head')->insertOrIgnore(['id' => 1, 'last_hash' => null, 'updated_at' => now()]);
    // Permissions are the policies' tests; this drill is about the locks.
    Gate::before(fn (): bool => true);
    // Activating a lease issues its contract (P03-03b) and a bill its invoice (P03-05c); their PDFs are not this drill's business.
    Queue::fake([RenderLeaseContract::class, RenderInvoice::class]);

    $this->organization = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd']);
    $this->user = User::factory()->create();
});

afterEach(function () {
    app(OrganizationContext::class)->clear();

    foreach (BILL_DRILL_TABLES as $table) {
        DB::table($table)->delete();
    }

    DB::table('audit_chain_head')->update(['last_hash' => null]);
});

/** Starts a fixture process (tests/Fixtures/Processes) against this test's database. */
function billDrillProcess(string $script, array $arguments): InvokedProcess
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
    ])->start([PHP_BINARY, 'tests/Fixtures/Processes/'.$script, ...$arguments]);
}

/**
 * Starts the processes, waits until all are ready, says go, and returns
 * every JSON line they printed.
 *
 * @param  list<list<string>>  $runs  each process's arguments, the barrier folder added last
 * @return list<array<string, mixed>>
 */
function runTogether(string $script, array $runs): array
{
    $barrier = sys_get_temp_dir().'/goproperty-bills-'.Str::random(10);
    File::ensureDirectoryExists($barrier);
    $lines = [];

    try {
        $running = array_map(fn (array $arguments): InvokedProcess => billDrillProcess($script, [...$arguments, $barrier]), $runs);
        $deadline = microtime(true) + 90;

        while (count(File::glob($barrier.'/ready-*')) < count($running)) {
            foreach ($running as $process) {
                expect($process->running() || $process->latestErrorOutput() === '')->toBeTrue($process->latestErrorOutput());
            }

            expect(microtime(true))->toBeLessThan($deadline, 'The processes did not boot in time.');
            usleep(10_000);
        }

        File::put($barrier.'/go', '');

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

    return $lines;
}

/** @param  list<array<string, mixed>>  $lines */
function processesOverlapped(array $lines): bool
{
    $byPid = collect($lines)->groupBy('pid');

    return $byPid->count() > 1 && $byPid->map(fn ($own) => $own->min('started'))->max() < $byPid->map(fn ($own) => $own->max('ended'))->min();
}

it('acceptance: bills every period once when several runs of bill generation work on the same leases at once, numbers without gaps', function () {
    $today = CarbonImmutable::now('Africa/Lagos')->startOfDay();
    $processes = 4;

    // Committed set-up: leases behind by several periods, so each run has many periods to race for.
    $leaseIds = app(OrganizationContext::class)->run($this->organization, function () use ($today): array {
        $building = Property::factory()->create(['name' => 'Palm Court']);
        $ids = [];
        $terms = [
            ['billingCycle' => 'weekly', 'startDate' => $today->subDays(35)->toDateString(), 'endDate' => $today->addDays(300)->toDateString(), 'rent' => '25,000'],
            ['billingCycle' => 'daily', 'startDate' => $today->subDays(6)->toDateString(), 'endDate' => $today->addDays(60)->toDateString(), 'rent' => '5,000'],
            ['billingCycle' => 'monthly', 'startDate' => $today->subMonthsNoOverflow(3)->toDateString(), 'endDate' => $today->addMonthsNoOverflow(9)->toDateString(), 'rent' => '125,000'],
            ['billingCycle' => 'annual', 'startDate' => $today->toDateString(), 'endDate' => $today->addYear()->subDay()->toDateString(), 'rent' => '1,500,000'],
        ];

        foreach (range(1, 12) as $n) {
            $lease = app(LeaseService::class)->draft($this->user, [
                'tenantId' => TenantProfile::factory()->create()->id,
                'unitId' => Unit::factory()->in($building)->create(['unit_label' => "Flat {$n}"])->id,
                'deposit' => '0',
            ] + $terms[$n % 4]);
            $ids[] = app(LeaseService::class)->activate($this->user, $lease)->id;
        }

        return $ids;
    });

    $lines = runTogether('bill-generation.php', array_fill(0, $processes, [(string) $this->organization->id]));

    [$bills, $counter, $codes, $leases] = app(OrganizationContext::class)->run($this->organization, fn (): array => [
        Bill::query()->orderBy('id')->get(),
        (int) DB::table('document_counters')->where('organization_id', $this->organization->id)->where('doc_type', 'invoice')->sum('last_seq'),
        DB::table('document_verifications')->where('organization_id', $this->organization->id)->where('kind', 'invoice')->count(),
        Lease::query()->whereKey($leaseIds)->get(),
    ]);
    $issued = collect($lines)->flatMap(fn (array $line): array => $line['issued']);

    // Every process ran, alongside the others, and none failed.
    expect($lines)->toHaveCount($processes)
        ->and(collect($lines)->pluck('pid')->unique())->toHaveCount($processes)
        ->and(processesOverlapped($lines))->toBeTrue()
        ->and(collect($lines)->pluck('finished')->unique()->all())->toBe([true])
        ->and(collect($lines)->flatMap(fn (array $line): array => $line['reported'])->all())->toBe([]);

    // Each due period billed exactly once: weekly 7 (5 behind, this week, next), daily 14, monthly 4, annual 1 — three leases each.
    $expected = 3 * (7 + 14 + 4 + 1);
    $keys = $bills->map(fn (Bill $bill): string => $bill->lease_id.'@'.$bill->period_start?->toDateString());
    expect($bills)->toHaveCount($expected)
        ->and($keys->unique())->toHaveCount($expected)
        ->and($issued->sort()->values()->all())->toBe($bills->pluck('id')->all())
        ->and($codes)->toBe($expected)
        ->and($bills->pluck('payment_reference')->unique())->toHaveCount($expected);

    // Every lease moved past the lead window, none past a period it did not bill.
    $window = $today->addDays(7)->toDateString();
    foreach ($leases as $lease) {
        $billed = $bills->where('lease_id', $lease->id);
        expect($lease->next_billing_date?->toDateString())->toBeGreaterThan($window)
            ->and($billed->max(fn (Bill $bill) => $bill->period_start?->toDateString()))->toBeLessThanOrEqual($window);
    }

    // Numbers 1..N per year of issue, no gap, no duplicate.
    $sequences = $bills->map(fn (Bill $bill): int => (int) Str::afterLast($bill->number, '-'))->sort()->values()->all();
    expect($sequences)->toBe(range(1, $expected))
        ->and($counter)->toBe($expected)
        ->and($bills->pluck('number')->all())->each->toMatch('/^INV-ADR-\d{4}-\d{6}$/');

    // A rerun afterwards finds nothing to do.
    $again = runTogether('bill-generation.php', [[(string) $this->organization->id], [(string) $this->organization->id]]);
    expect(collect($again)->flatMap(fn (array $line): array => $line['issued'])->all())->toBe([])
        ->and(app(OrganizationContext::class)->run($this->organization, fn () => Bill::query()->count()))->toBe($expected);
});

it('never settles a bill beyond its amount when payments to it are confirmed at once', function () {
    $bill = app(OrganizationContext::class)->run($this->organization, function (): Bill {
        $lease = app(LeaseService::class)->draft($this->user, [
            'tenantId' => TenantProfile::factory()->create()->id,
            'unitId' => Unit::factory()->in(Property::factory()->create())->create()->id,
            'billingCycle' => 'annual',
            'startDate' => CarbonImmutable::now('Africa/Lagos')->toDateString(),
            'endDate' => CarbonImmutable::now('Africa/Lagos')->addYear()->subDay()->toDateString(),
            'rent' => '100,000',
            'deposit' => '0',
        ]);
        app(LeaseService::class)->activate($this->user, $lease);
        app(RentBillGenerator::class)->generate();

        return Bill::query()->sole();
    });

    // 6 processes × 10 payments of ₦3,000 against a ₦100,000 bill: only 33 fit.
    $lines = runTogether('bill-payments.php', array_fill(0, 6, [(string) $this->organization->id, (string) $bill->id, '10', '300000']));
    $outcomes = collect($lines)->countBy('outcome')->all();
    $stored = app(OrganizationContext::class)->run($this->organization, fn (): Bill => Bill::query()->findOrFail($bill->id));

    expect($lines)->toHaveCount(60)
        ->and(processesOverlapped($lines))->toBeTrue()
        ->and($outcomes)->toEqual(['applied' => 33, 'refused' => 27])
        ->and(collect($lines)->where('outcome', 'refused')->pluck('reason')->unique()->values()->all())->toBe([BillNotPayable::TOO_MUCH])
        ->and($stored->paid_kobo->kobo)->toBe(33 * 300_000)
        ->and($stored->outstanding()->kobo)->toBe(100_000)
        ->and(collect($lines)->where('outcome', 'applied')->pluck('paid')->sort()->values()->all())->toBe(range(300_000, 33 * 300_000, 300_000));
});

it('refuses to issue, lock or settle bills, or lock a lease for billing, outside a transaction', function () {
    $context = app(OrganizationContext::class);
    $snapshot = $context->run($this->organization, fn () => app(BrandingSnapshots::class)->take($this->organization));
    $draft = new BillDraft(BillType::Other, BillableType::Unit, 1, 1, 'Probe', CarbonImmutable::now(), [new BillLine('Probe', Money::naira(1))]);

    expect(DB::transactionLevel())->toBe(0)
        ->and(fn () => $context->run($this->organization, fn () => app(BillIssuer::class)->issue($draft, $snapshot)))->toThrow(LogicException::class, 'inside a transaction')
        ->and(fn () => $context->run($this->organization, fn () => app(BillLedger::class)->apply(1, Money::naira(1))))->toThrow(LogicException::class)
        ->and(fn () => $context->run($this->organization, fn () => app(BillLedger::class)->release(1, Money::naira(1))))->toThrow(LogicException::class)
        ->and(fn () => $context->run($this->organization, fn () => app(BillLedger::class)->lock([1])))->toThrow(LogicException::class)
        ->and(fn () => $context->run($this->organization, fn () => app(LeaseBillingSchedule::class)->lock(1)))->toThrow(LogicException::class);
});
