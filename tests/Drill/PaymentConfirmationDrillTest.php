<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
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
use Modules\Billing\Bills\RentBillGenerator;
use Modules\Billing\Jobs\RenderInvoice;
use Modules\Billing\Models\Bill;
use Modules\Leasing\Jobs\RenderLeaseContract;
use Modules\Leasing\Leases\LeaseService;
use Modules\Payments\Credit\TenantCredit;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentAllocation;
use Modules\Payments\Models\TenantCreditBalance;
use Modules\Payments\Models\TenantCreditEntry;
use Modules\Payments\Payments\PaymentService;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\Tenants\Models\TenantProfile;

/*
 * P03-10 acceptance with real concurrency: "concurrent confirmations of
 * the same bill cannot over-settle (locked transaction test)", and a
 * payment confirmed from two places at once is confirmed once. Several PHP
 * processes, each with its own database connection, confirm payments
 * through PaymentService at the same moment: the payment's row lock, the
 * bills' row locks (Billing's BillLedger::lock/apply) and the tenant's
 * credit row lock are what keep them apart. Drill: the processes see only
 * committed rows, so this test commits and cleans up after itself.
 */

const PAYMENT_DRILL_TABLES = ['payment_allocations', 'tenant_credit_entries', 'tenant_credit_balances', 'payments', 'bill_items', 'bills', 'document_verifications', 'lease_contracts', 'lease_deposit_entries', 'leases', 'units', 'properties', 'tenant_profiles', 'document_counters', 'settings', 'organizations', 'users', 'activity_log'];

beforeEach(function () {
    // Activating a lease issues its contract (P03-03b); its PDF is not this drill's business.
    Queue::fake([RenderLeaseContract::class, RenderInvoice::class]);
    DB::table('audit_chain_head')->insertOrIgnore(['id' => 1, 'last_hash' => null, 'updated_at' => now()]);
    // Permissions are the policies' tests; this drill is about the locks.
    Gate::before(fn (): bool => true);

    $this->organization = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd']);
    $this->user = User::factory()->create();

    // A tenant with one yearly bill of ₦100,000.
    [$this->tenant, $this->bill] = app(OrganizationContext::class)->run($this->organization, function (): array {
        $tenant = TenantProfile::factory()->create();
        $lease = app(LeaseService::class)->draft($this->user, [
            'tenantId' => $tenant->id,
            'unitId' => Unit::factory()->in(Property::factory()->create())->create()->id,
            'billingCycle' => 'annual',
            'startDate' => CarbonImmutable::now('Africa/Lagos')->toDateString(),
            'endDate' => CarbonImmutable::now('Africa/Lagos')->addYear()->subDay()->toDateString(),
            'rent' => '100,000',
            'deposit' => '0',
        ]);
        app(LeaseService::class)->activate($this->user, $lease);
        app(RentBillGenerator::class)->generate();

        return [$tenant, Bill::query()->sole()];
    });
});

afterEach(function () {
    app(OrganizationContext::class)->clear();

    foreach (PAYMENT_DRILL_TABLES as $table) {
        DB::table($table)->delete();
    }

    DB::table('audit_chain_head')->update(['last_hash' => null]);
});

/**
 * Payments of the tenant waiting for confirmation, recorded for the bill.
 *
 * @return list<int>
 */
function pendingPaymentsForDrill(Organization $organization, User $user, TenantProfile $tenant, Bill $bill, int $count, string $naira): array
{
    return app(OrganizationContext::class)->run($organization, fn (): array => array_map(
        fn (): int => app(PaymentService::class)->record($user, [
            'tenantId' => (string) $tenant->id,
            'billId' => (string) $bill->id,
            'amount' => $naira,
            'method' => 'bank_transfer',
            'paidAt' => CarbonImmutable::now('Africa/Lagos')->toDateString(),
        ])->id,
        range(1, $count),
    ));
}

/** Starts a fixture process (tests/Fixtures/Processes) against this test's database. */
function paymentDrillProcess(string $script, array $arguments): InvokedProcess
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
function confirmTogether(array $runs): array
{
    $barrier = sys_get_temp_dir().'/goproperty-payments-'.Str::random(10);
    File::ensureDirectoryExists($barrier);
    $lines = [];

    try {
        $running = array_map(fn (array $arguments): InvokedProcess => paymentDrillProcess('payment-confirmations.php', [...$arguments, $barrier]), $runs);
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
function confirmationsOverlapped(array $lines): bool
{
    $byPid = collect($lines)->groupBy('pid');

    return $byPid->count() > 1 && $byPid->map(fn ($own) => $own->min('started'))->max() < $byPid->map(fn ($own) => $own->max('ended'))->min();
}

/** What the drill left in the database, read in the organization. */
function paymentDrillState(Organization $organization, TenantProfile $tenant, Bill $bill): array
{
    return app(OrganizationContext::class)->run($organization, fn (): array => [
        'bill' => Bill::query()->findOrFail($bill->id),
        'payments' => Payment::query()->orderBy('id')->get(),
        'allocations' => PaymentAllocation::query()->orderBy('id')->get(),
        'credit' => TenantCreditEntry::query()->where('tenant_profile_id', $tenant->id)->orderBy('id')->get(),
        'balance' => TenantCreditBalance::query()->where('tenant_profile_id', $tenant->id)->first()?->balance_kobo->kobo ?? 0,
    ]);
}

it('acceptance: never settles a bill beyond its amount when payments to it are confirmed at once, the rest goes to credit', function () {
    // 6 processes × 5 payments of ₦7,000 for one ₦100,000 bill: ₦210,000 in all.
    $ids = pendingPaymentsForDrill($this->organization, $this->user, $this->tenant, $this->bill, 30, '7,000');
    $runs = array_map(fn (array $own): array => [(string) $this->organization->id, (string) $this->user->id, implode(',', $own), 'auto'], array_chunk($ids, 5));

    $lines = confirmTogether($runs);
    $state = paymentDrillState($this->organization, $this->tenant, $this->bill);

    expect($lines)->toHaveCount(30)
        ->and(confirmationsOverlapped($lines))->toBeTrue()
        ->and(collect($lines)->countBy('outcome')->all())->toEqual(['confirmed' => 30]);

    // The bill took exactly its amount: 14 whole payments and ₦2,000 of a 15th.
    expect($state['bill']->paid_kobo->kobo)->toBe(10_000_000)
        ->and($state['bill']->status->value)->toBe('paid')
        ->and((int) $state['allocations']->sum(fn (PaymentAllocation $allocation): int => $allocation->amount_kobo->kobo))->toBe(10_000_000)
        ->and($state['allocations'])->toHaveCount(15)
        ->and($state['allocations']->pluck('bill_id')->unique()->all())->toBe([$this->bill->id]);

    // The other ₦110,000 is the tenant's credit: entries and balance agree.
    expect((int) $state['credit']->sum(fn (TenantCreditEntry $entry): int => $entry->amount_kobo->kobo))->toBe(11_000_000)
        ->and($state['balance'])->toBe(11_000_000)
        ->and($state['credit'])->toHaveCount(16)
        ->and($state['credit']->pluck('reason')->map->value->unique()->all())->toBe(['overpayment']);

    // Every payment confirmed once, and fully accounted for: its allocation plus its credit is its amount.
    foreach ($state['payments'] as $payment) {
        $allocated = (int) $state['allocations']->where('payment_id', $payment->id)->sum(fn (PaymentAllocation $allocation): int => $allocation->amount_kobo->kobo);
        $credited = (int) $state['credit']->where('payment_id', $payment->id)->sum(fn (TenantCreditEntry $entry): int => $entry->amount_kobo->kobo);

        expect($payment->status->value)->toBe('confirmed')
            ->and($allocated + $credited)->toBe(700_000);
    }

    expect(AuditEntry::query()->where('event', 'payment.confirmed')->count())->toBe(30);
});

it('acceptance: refuses explicit splits beyond what the bill still owes when they are confirmed at once', function () {
    // 6 processes × 5 payments of ₦30,000, each confirmed as "₦30,000 to the bill": only 3 fit.
    $ids = pendingPaymentsForDrill($this->organization, $this->user, $this->tenant, $this->bill, 30, '30,000');
    $runs = array_map(fn (array $own): array => [(string) $this->organization->id, (string) $this->user->id, implode(',', $own), $this->bill->id.':3000000'], array_chunk($ids, 5));

    $lines = confirmTogether($runs);
    $state = paymentDrillState($this->organization, $this->tenant, $this->bill);

    expect(confirmationsOverlapped($lines))->toBeTrue()
        ->and(collect($lines)->countBy('outcome')->all())->toEqual(['confirmed' => 3, 'refused' => 27])
        ->and(collect($lines)->where('outcome', 'refused')->pluck('message')->every(fn (string $message): bool => str_contains($message, 'is more than that')))->toBeTrue()
        ->and($state['bill']->paid_kobo->kobo)->toBe(9_000_000)
        ->and($state['bill']->status->value)->toBe('partially_paid')
        ->and($state['allocations'])->toHaveCount(3)
        ->and($state['credit'])->toHaveCount(0)
        ->and($state['payments']->where('status.value', 'confirmed'))->toHaveCount(3)
        ->and($state['payments']->where('status.value', 'pending_confirmation'))->toHaveCount(27);
});

it('sub: confirms a payment once when several processes confirm the same payments at once', function () {
    // 10 payments of ₦10,000; 4 processes each try to confirm all of them, in their own order.
    $ids = pendingPaymentsForDrill($this->organization, $this->user, $this->tenant, $this->bill, 10, '10,000');
    $runs = array_map(fn (int $n): array => [(string) $this->organization->id, (string) $this->user->id, implode(',', $n % 2 === 0 ? $ids : array_reverse($ids)), 'auto'], range(1, 4));

    $lines = confirmTogether($runs);
    $state = paymentDrillState($this->organization, $this->tenant, $this->bill);
    $confirmed = collect($lines)->where('outcome', 'confirmed');

    expect($lines)->toHaveCount(40)
        ->and(confirmationsOverlapped($lines))->toBeTrue()
        ->and(collect($lines)->countBy('outcome')->all())->toEqual(['confirmed' => 10, 'refused' => 30])
        ->and($confirmed->pluck('payment')->sort()->values()->all())->toBe($ids)
        ->and(collect($lines)->where('outcome', 'refused')->pluck('message')->unique()->values()->all())->toBe(['This payment is already confirmed.'])
        ->and($state['allocations'])->toHaveCount(10)
        ->and($state['allocations']->pluck('payment_id')->sort()->values()->all())->toBe($ids)
        ->and($state['bill']->paid_kobo->kobo)->toBe(10_000_000)
        ->and($state['balance'])->toBe(0)
        ->and(AuditEntry::query()->where('event', 'payment.confirmed')->count())->toBe(10);
});

it('refuses to change tenant credit outside a transaction', function () {
    $context = app(OrganizationContext::class);

    expect(DB::transactionLevel())->toBe(0)
        ->and(fn () => $context->run($this->organization, fn () => app(TenantCredit::class)->addOverpayment(new Payment, Money::naira(1), null)))->toThrow(LogicException::class, 'inside a transaction');
});
