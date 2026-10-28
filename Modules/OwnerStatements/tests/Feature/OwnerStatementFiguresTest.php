<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Modules\Billing\Models\Bill;

require_once __DIR__.'/statement-world.php';

/*
 * P08-05 AC: the net due to the landlord reconciles against a
 * hand-computed fixture (statement-world.php): rent and utilities across
 * two buildings and several units, a part payment, a voided bill excluded,
 * a refund netted, a voided receipt counted in the month it happened,
 * commission at a set rate, one charged expense and one not, utilities on
 * and off. Money in kobo; months in Lagos.
 */

beforeEach(function () {
    Notification::fake();
    statementWorld();
});

afterEach(fn () => billsFreshRequest());

it('acceptance: reconciles January\'s net due with a hand-computed fixture, utilities off then on', function () {
    // The fixture's bills as the world made them.
    expect(storedBill($this->acme, $this->bill)->amount_kobo->kobo)->toBe(150_000_000)
        ->and($this->bisiJanRent->amount_kobo->kobo)->toBe(20_000_000)
        ->and($this->chukaJanRent->amount_kobo->kobo)->toBe(15_000_000)
        ->and($this->bisiJanLevy->amount_kobo->kobo)->toBe(2_500_000)
        ->and(storedBill($this->acme, $this->bisiJanRent)->paid_kobo->kobo)->toBe(10_000_000)
        ->and(storedBill($this->acme, $this->chukaFebRent)->paid_kobo->kobo)->toBe(2_000_000)
        ->and(storedBill($this->acme, $this->bisiFebLevy)->status->value)->toBe('void')
        ->and(creditOf($this->acme, $this->chuka)->kobo)->toBe(0);

    // Commission not set: none taken, clearly labelled.
    $unset = statementOf($this->acme, $this->ngoziAtAcme, '2027-01');
    expect($unset->statement->commissionSet())->toBeFalse()
        ->and(statementFigures($unset))->toBe([177_000_000, 2_500_000, 0, 4_000_000, 173_000_000]);

    // 10% commission, utilities kept from the owner (the default).
    setStatementTerms($this->acme, $this->owner, $this->ngoziAtAcme, ['commission' => '10']);
    $january = statementOf($this->acme, $this->ngoziAtAcme, '2027-01');

    // Palm Court: Kemi's ₦1,500,000; the ₦60,000 expense is not charged.
    expect(statementFigures($january, $this->palm->id))->toBe([150_000_000, 0, 15_000_000, 0, 135_000_000])
        // Marina Heights: Bisi's ₦100,000 part payment + Chuka's ₦150,000 + his ₦20,000 credit used
        // (paid ahead; his ₦30,000 overpayment was refunded: netted), the ₦25,000 levy, the ₦40,000 charged expense.
        ->and(statementFigures($january, $this->marina->id))->toBe([27_000_000, 2_500_000, 2_700_000, 4_000_000, 20_300_000])
        ->and(statementFigures($january))->toBe([177_000_000, 2_500_000, 17_700_000, 4_000_000, 155_300_000])
        ->and($january->statement->commissionRate())->toBe('10%');

    // Per unit, as the PDF prints it.
    $marina = collect($january->statement->buildings)->firstWhere('propertyId', $this->marina->id);
    expect(array_map(fn ($unit) => [$unit->unit, $unit->rent->kobo, $unit->utilities->kobo], $marina->units))
        ->toBe([['Unit 1', 10_000_000, 2_500_000], ['Unit 2', 17_000_000, 0]])
        ->and(array_map(fn ($line) => [$line->kind, $line->amount->kobo], $marina->collections))
        ->toBe([['payment', 10_000_000], ['payment', 2_500_000], ['payment', 15_000_000], ['credit', 2_000_000]]);

    // Bills issued in January: February's, the voided levy listed as VOID; no money on it.
    expect(array_map(fn ($bill) => [$bill->unit, $bill->typeLabel, $bill->status], $marina->bills))
        ->toEqualCanonicalizing([['Unit 1', 'Rent', 'unpaid'], ['Unit 1', 'Utility', 'void'], ['Unit 2', 'Rent', 'partially_paid']]);

    // Utilities passed to the owner: the ₦25,000 counts.
    setStatementTerms($this->acme, $this->owner, $this->ngoziAtAcme, ['commission' => '10', 'utilitiesToOwner' => true]);
    expect(statementFigures(statementOf($this->acme, $this->ngoziAtAcme, '2027-01')))->toBe([177_000_000, 2_500_000, 17_700_000, 4_000_000, 157_800_000])
        ->and(statementFigures(statementOf($this->acme, $this->ngoziAtAcme, '2027-01'), $this->marina->id))->toBe([27_000_000, 2_500_000, 2_700_000, 4_000_000, 22_800_000]);

    // A rate with a fraction (7.5%, per building, rounded to the kobo), utilities off again.
    setStatementTerms($this->acme, $this->owner, $this->ngoziAtAcme, ['commission' => '7.5']);
    expect(statementFigures(statementOf($this->acme, $this->ngoziAtAcme, '2027-01')))->toBe([177_000_000, 2_500_000, 13_275_000, 4_000_000, 159_725_000]);
});

it('acceptance: counts a voided receipt, negative, in the month it was voided, and leaves the month of the payment alone', function () {
    setStatementTerms($this->acme, $this->owner, $this->ngoziAtAcme, ['commission' => '10']);

    $this->travelTo('2027-02-03 10:00:00');
    voidReceiptAlone($this->acme, $this->owner, receiptOf($this->acme, $this->chukaPayment));

    expect(statementFigures(statementOf($this->acme, $this->ngoziAtAcme, '2027-01')))->toBe([177_000_000, 2_500_000, 17_700_000, 4_000_000, 155_300_000]);

    $february = statementOf($this->acme, $this->ngoziAtAcme, '2027-02');
    expect(statementFigures($february, $this->marina->id))->toBe([-15_000_000, 0, -1_500_000, 0, -13_500_000])
        ->and(statementFigures($february))->toBe([-15_000_000, 0, -1_500_000, 0, -13_500_000])
        ->and(collect($february->statement->buildings)->firstWhere('propertyId', $this->marina->id)->collections[0]->kind)->toBe('receipt_voided');
});

it('keeps other owners\' and other agencies\' money out of the owner\'s statement', function () {
    setStatementTerms($this->acme, $this->owner, $this->ngoziAtAcme, ['commission' => '10']);
    $january = statementOf($this->acme, $this->ngoziAtAcme, '2027-01');

    expect(array_map(fn ($building) => $building->name, $january->statement->buildings))->toBe(['Marina Heights', 'Palm Court']);

    // Femi's Cedar House at Adeyemi: his own statement.
    expect(statementFigures(statementOf($this->acme, $this->femiAtAcme, '2027-01')))->toBe([180_000_000, 0, 0, 0, 180_000_000]);

    // Ngozi at Bello Homes: Lagoon View only, commission not set there.
    $bello = statementOf($this->other, $this->ngoziAtBello, '2027-01');
    expect(array_map(fn ($building) => $building->name, $bello->statement->buildings))->toBe(['Lagoon View'])
        ->and(statementFigures($bello))->toBe([90_000_000, 0, 0, 0, 90_000_000])
        ->and($bello->statement->commissionSet())->toBeFalse();
});

it('acceptance: a payment confirmed after a month is settled counts in the next month, and the settled month never changes', function () {
    setStatementTerms($this->acme, $this->owner, $this->ngoziAtAcme, ['commission' => '10']);

    $this->travelTo('2027-02-02 10:00:00');
    $settled = settleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01', '2027-02-02', 'TRF-NGOZI-JAN');
    $snapshot = $settled->figures;
    expect($settled->net_due_kobo)->toBe(155_300_000);

    // Bisi pays the rest of January's rent on 4 February; Chuka's receipt is voided on 5 February.
    $this->travelTo('2027-02-04 10:00:00');
    confirmedPayment($this->acme, $this->owner, $this->bisi, '100,000', [], [$this->bisiJanRent->id => '100,000']);
    $this->travelTo('2027-02-05 10:00:00');
    voidReceiptAlone($this->acme, $this->owner, receiptOf($this->acme, $this->chukaPayment));
    // The terms change too: a settled month keeps the ones it was settled with.
    setStatementTerms($this->acme, $this->owner, $this->ngoziAtAcme, ['commission' => '12', 'utilitiesToOwner' => true]);

    $january = statementOf($this->acme, $this->ngoziAtAcme, '2027-01');
    expect($january->isSettled())->toBeTrue()
        ->and(statementFigures($january))->toBe([177_000_000, 2_500_000, 17_700_000, 4_000_000, 155_300_000])
        ->and($january->statement->commissionRate())->toBe('10%')
        ->and(inOrganization($this->acme, fn () => $settled->fresh()?->figures))->toEqual($snapshot);

    // February: +₦100,000 (Bisi) − ₦150,000 (the voided receipt), at 12%.
    expect(statementFigures(statementOf($this->acme, $this->ngoziAtAcme, '2027-02'), $this->marina->id))->toBe([-5_000_000, 0, -600_000, 0, -4_400_000]);

    // Unsettled again (with a reason), January is worked out from the records again:
    // the payment and the void stay in February.
    unsettleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01');
    expect(statementFigures(statementOf($this->acme, $this->ngoziAtAcme, '2027-01')))->toBe([177_000_000, 2_500_000, 21_240_000, 4_000_000, 154_260_000]);
});
