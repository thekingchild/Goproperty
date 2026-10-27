<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Bills\BillService;
use Modules\Billing\Contracts\BillBalance;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Contracts\BillNotPayable;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Models\Bill;

require_once __DIR__.'/helpers.php';

/*
 * P03-04b: Contracts\BillLedger, through which Payments (P03-10a) settles
 * bills: each change locks the bill's row inside the caller's transaction,
 * never settles a bill beyond its amount, refuses a void bill, and keeps
 * the status in step with the money (paid, partly paid, overdue).
 */

beforeEach(function () {
    // Bills issue their invoice PDF on the pdf queue (P03-05c); not this test's business.
    holdInvoicePdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo']);
    // Yearly ₦1,500,000 from 1 Jan 2027 and monthly ₦125,000 since 1 Dec 2026 (one bill already due).
    $this->yearly = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), $this->kemi);
    $this->monthly = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), $this->kemi, ['billingCycle' => 'monthly', 'startDate' => '2026-12-01', 'endDate' => '2027-11-30', 'rent' => '125,000']);
    runBillGeneration();
    $this->bills = billsOf($this->acme);
    [$this->december, $this->january, $this->rent] = [
        $this->bills->first(fn (Bill $bill) => $bill->lease_id === $this->monthly->id && $bill->period_start?->toDateString() === '2026-12-01'),
        $this->bills->first(fn (Bill $bill) => $bill->lease_id === $this->monthly->id && $bill->period_start?->toDateString() === '2027-01-01'),
        $this->bills->first(fn (Bill $bill) => $bill->lease_id === $this->yearly->id),
    ];
});

afterEach(fn () => billsFreshRequest());

/** Runs the callback with the ledger inside a transaction in the organization. */
function inLedger(Organization $organization, Closure $callback): mixed
{
    return inOrganization($organization, fn () => DB::transaction(fn () => $callback(app(BillLedger::class)), 3));
}

it('settles a bill in parts, keeping its status in step with the money', function () {
    $after = [];
    $after[] = inLedger($this->acme, fn (BillLedger $ledger): BillBalance => $ledger->apply($this->rent->id, Money::naira(500_000)));
    $after[] = inLedger($this->acme, fn (BillLedger $ledger): BillBalance => $ledger->apply($this->rent->id, Money::naira(1_000_000)));

    expect(array_map(fn (BillBalance $b): array => [$b->status, $b->paid->kobo, $b->outstanding->kobo], $after))->toBe([
        ['partially_paid', 50_000_000, 100_000_000],
        ['paid', 150_000_000, 0],
    ])
        ->and(storedBill($this->acme, $this->rent)->status)->toBe(BillStatus::Paid)
        ->and(storedBill($this->acme, $this->rent)->paid_kobo->kobo)->toBe(150_000_000);

    // A reversed payment gives the money back to the bill.
    $released = inLedger($this->acme, fn (BillLedger $ledger): BillBalance => $ledger->release($this->rent->id, Money::naira(1_000_000)));
    expect([$released->status, $released->paid->kobo, $released->outstanding->kobo])->toBe(['partially_paid', 50_000_000, 100_000_000]);
    $released = inLedger($this->acme, fn (BillLedger $ledger): BillBalance => $ledger->release($this->rent->id, Money::naira(500_000)));
    expect($released->status)->toBe('unpaid');
});

it('keeps a bill past its due date overdue until it is paid in full', function () {
    // December's bill was due on 1 Dec: overdue from the day it was issued.
    expect($this->december->status)->toBe(BillStatus::Overdue);

    $part = inLedger($this->acme, fn (BillLedger $ledger): BillBalance => $ledger->apply($this->december->id, Money::naira(25_000)));
    $full = inLedger($this->acme, fn (BillLedger $ledger): BillBalance => $ledger->apply($this->december->id, Money::naira(100_000)));
    $back = inLedger($this->acme, fn (BillLedger $ledger): BillBalance => $ledger->release($this->december->id, Money::naira(100_000)));

    expect([$part->status, $full->status, $back->status])->toBe(['overdue', 'paid', 'overdue']);
});

it('never settles a bill beyond what it owes, and changes nothing when it refuses', function () {
    $refused = function (Closure $call, string $reason): void {
        expect(fn () => inLedger($this->acme, $call))->toThrow(fn (BillNotPayable $e) => expect($e->reason)->toBe($reason));
    };

    $refused(fn (BillLedger $ledger) => $ledger->apply($this->rent->id, Money::naira(1_500_000)->plus(Money::kobo(1))), BillNotPayable::TOO_MUCH);
    $refused(fn (BillLedger $ledger) => $ledger->apply($this->rent->id, Money::zero()), BillNotPayable::NOT_POSITIVE);
    $refused(fn (BillLedger $ledger) => $ledger->apply($this->rent->id, Money::naira(-1)), BillNotPayable::NOT_POSITIVE);
    $refused(fn (BillLedger $ledger) => $ledger->release($this->rent->id, Money::naira(1)), BillNotPayable::TOO_MUCH);
    $refused(fn (BillLedger $ledger) => $ledger->apply(999_999, Money::naira(1)), BillNotPayable::UNKNOWN);

    // Two payments in one transaction: the second sees the first.
    $refused(function (BillLedger $ledger): void {
        $ledger->apply($this->january->id, Money::naira(100_000));
        $ledger->apply($this->january->id, Money::naira(25_001));
    }, BillNotPayable::TOO_MUCH);

    expect(storedBill($this->acme, $this->rent)->paid_kobo->kobo)->toBe(0)
        ->and(storedBill($this->acme, $this->january)->paid_kobo->kobo)->toBe(0)
        ->and(storedBill($this->acme, $this->january)->status)->toBe(BillStatus::Unpaid);
});

it('accepts nothing on a void bill', function () {
    inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, $this->january, 'Entered twice'));

    expect(fn () => inLedger($this->acme, fn (BillLedger $ledger) => $ledger->apply($this->january->id, Money::naira(1))))
        ->toThrow(fn (BillNotPayable $e) => expect($e->reason)->toBe(BillNotPayable::VOID))
        ->and(inLedger($this->acme, fn (BillLedger $ledger) => $ledger->balance($this->january->id))->outstanding->kobo)->toBe(0)
        ->and(storedBill($this->acme, $this->january)->paid_kobo->kobo)->toBe(0)
        ->and(storedBill($this->acme, $this->january)->status)->toBe(BillStatus::Void);
});

it('locks several bills at once in id order and gives their balances', function () {
    $locked = inLedger($this->acme, fn (BillLedger $ledger): array => $ledger->lock([$this->rent->id, $this->december->id, 999_999, $this->january->id, $this->rent->id]));

    expect(array_keys($locked))->toBe(collect([$this->rent->id, $this->december->id, $this->january->id])->sort()->values()->all())
        ->and($locked[$this->rent->id]->outstanding->kobo)->toBe(150_000_000)
        ->and($locked[$this->rent->id]->number)->toBe($this->rent->number)
        ->and($locked[$this->rent->id]->tenantProfileId)->toBe($this->kemi->id)
        ->and($locked[$this->rent->id]->propertyId)->toBe($this->palm->id);
});

it('lists a tenant\'s open bills oldest due first, and finds a bill by its payment reference as typed', function () {
    inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, $this->january, 'Entered twice'));
    inLedger($this->acme, fn (BillLedger $ledger) => $ledger->apply($this->december->id, Money::naira(125_000)));
    $reference = $this->rent->payment_reference;
    $typed = strtolower(str_replace('-', ' ', $reference));

    $open = inOrganization($this->acme, fn (): array => app(BillLedger::class)->openBills($this->kemi->id));

    expect(array_map(fn (BillBalance $b): int => $b->id, $open))->toBe([$this->rent->id])
        ->and(inOrganization($this->acme, fn () => app(BillLedger::class)->findByReference($typed))?->id)->toBe($this->rent->id)
        ->and(inOrganization($this->acme, fn () => app(BillLedger::class)->findByReference(str_replace('-', '', $reference)))?->id)->toBe($this->rent->id)
        ->and(inOrganization($this->acme, fn () => app(BillLedger::class)->findByReference('GP-NOTREAL')))->toBeNull()
        ->and(inOrganization($this->acme, fn () => app(BillLedger::class)->findByReference('hello')))->toBeNull();
});

it('never reaches another organization\'s bills', function () {
    $other = Organization::factory()->create(['slug' => 'bello-homes', 'name' => 'Bello Homes']);
    $foreignLease = activeLease($other, joinAs(User::factory()->create(), $other), unitIn($other, buildingIn($other)), tenantIn($other));
    runBillGeneration();
    $foreign = billsOf($other)->sole();

    expect(fn () => inLedger($this->acme, fn (BillLedger $ledger) => $ledger->apply($foreign->id, Money::naira(1))))
        ->toThrow(fn (BillNotPayable $e) => expect($e->reason)->toBe(BillNotPayable::UNKNOWN))
        ->and(inLedger($this->acme, fn (BillLedger $ledger) => $ledger->lock([$foreign->id])))->toBe([])
        ->and(inOrganization($this->acme, fn () => app(BillLedger::class)->balance($foreign->id)))->toBeNull()
        ->and(inOrganization($this->acme, fn () => app(BillLedger::class)->findByReference($foreign->payment_reference)))->toBeNull()
        ->and(inOrganization($this->acme, fn () => app(BillLedger::class)->openBills($foreignLease->tenant_profile_id)))->toBe([])
        ->and(storedBill($other, $foreign)->paid_kobo->kobo)->toBe(0);
});
