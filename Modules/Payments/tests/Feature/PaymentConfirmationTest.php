<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Bills\BillService;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Events\BillIssued;
use Modules\Billing\Models\Bill;
use Modules\Payments\Credit\TenantCredit;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Events\PaymentConfirmed;
use Modules\Payments\Events\PaymentRejected;
use Modules\Payments\Listeners\ApplyCreditToNewBill;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentAllocation;
use Modules\Payments\Models\TenantCreditBalance;
use Modules\Payments\Models\TenantCreditEntry;
use Modules\Payments\Payments\PaymentRefused;
use Modules\Payments\Payments\PaymentService;
use Modules\Payments\Policies\PaymentPolicy;

require_once __DIR__.'/helpers.php';

/*
 * P03-10a: confirming payments (ARCHITECTURE.md § 6.4, DATABASE_SCHEMA.md
 * § 4 and its integrity rules 2 and 3): a payment settles the bill it was
 * for, then the tenant's open bills oldest due first, under the bills'
 * locks, in one transaction; what is left becomes tenant credit, which the
 * next bill issued takes (once); a void bill accepts nothing; a payment is
 * confirmed or rejected once, and a confirmed one is never changed or
 * deleted. The concurrent cases are in tests/Drill/PaymentConfirmationDrillTest.php.
 */

beforeEach(function () {
    holdInvoicePdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348030000001', 'email' => 'kemi@example.test']);
    // A monthly lease of ₦100,000 from 1 Jan 2027: January's bill is issued on 25 Dec (7 days' lead).
    $this->lease = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']), $this->kemi, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    runBillGeneration();
    $this->january = billsOfTenant($this->acme, $this->kemi)->sole();
});

afterEach(fn () => billsFreshRequest());

it('acceptance: puts an overpayment to the tenant\'s credit and applies it to the next bills as they are issued', function () {
    expect($this->january->amount_kobo->kobo)->toBe(10_000_000);

    $payment = confirmedPayment($this->acme, $this->owner, $this->kemi, '250,000');

    // January settled, ₦150,000 to credit.
    expect(storedPayment($this->acme, $payment)->status)->toBe(PaymentStatus::Confirmed)
        ->and(allocationsOf($this->acme, $payment))->toBe([$this->january->id => 10_000_000])
        ->and(storedBill($this->acme, $this->january)->status)->toBe(BillStatus::Paid)
        ->and(creditOf($this->acme, $this->kemi)->kobo)->toBe(15_000_000)
        ->and(creditEntriesOf($this->acme, $this->kemi))->toBe([['overpayment', 15_000_000, null]]);

    // February's bill (issued 25 Jan) takes ₦100,000 of it and is paid.
    $this->travelTo('2027-01-25 09:00:00');
    runBillGeneration();
    $february = billsOfTenant($this->acme, $this->kemi)->last();
    expect($february->period_start?->toDateString())->toBe('2027-02-01')
        ->and(storedBill($this->acme, $february)->status)->toBe(BillStatus::Paid)
        ->and(storedBill($this->acme, $february)->paid_kobo->kobo)->toBe(10_000_000)
        ->and(creditOf($this->acme, $this->kemi)->kobo)->toBe(5_000_000);

    // March's (issued 22 Feb) takes the last ₦50,000 and is partly paid.
    $this->travelTo('2027-02-22 09:00:00');
    runBillGeneration();
    $march = billsOfTenant($this->acme, $this->kemi)->last();
    expect($march->period_start?->toDateString())->toBe('2027-03-01')
        ->and(storedBill($this->acme, $march)->status)->toBe(BillStatus::PartiallyPaid)
        ->and(storedBill($this->acme, $march)->paid_kobo->kobo)->toBe(5_000_000)
        ->and(creditOf($this->acme, $this->kemi)->kobo)->toBe(0);

    // April's finds no credit.
    $this->travelTo('2027-03-25 09:00:00');
    runBillGeneration();
    $april = billsOfTenant($this->acme, $this->kemi)->last();
    expect($april->period_start?->toDateString())->toBe('2027-04-01')
        ->and(storedBill($this->acme, $april)->status)->toBe(BillStatus::Unpaid)
        ->and(storedBill($this->acme, $april)->paid_kobo->kobo)->toBe(0);

    // The ledger: + overpayment, − each bill's share; its sum is the balance.
    expect(creditEntriesOf($this->acme, $this->kemi))->toBe([
        ['overpayment', 15_000_000, null],
        ['applied_to_bill', -10_000_000, $february->id],
        ['applied_to_bill', -5_000_000, $march->id],
    ]);
    $applied = AuditEntry::query()->where('event', 'credit.applied')->orderBy('id')->get();
    expect($applied)->toHaveCount(2)
        ->and($applied->pluck('log_name')->unique()->all())->toBe(['payments'])
        ->and($applied->map(fn (AuditEntry $entry) => [$entry->properties['bill_id'], $entry->properties['amount_kobo'], $entry->properties['credit_left_kobo']])->all())
        ->toBe([[$february->id, 10_000_000, 5_000_000], [$march->id, 5_000_000, 0]]);
});

it('settles the bill the payment was for first, then the tenant\'s open bills oldest due first, the rest to credit', function () {
    $this->travelTo('2027-02-22 09:00:00');
    runBillGeneration();
    [$january, $february, $march] = billsOfTenant($this->acme, $this->kemi)->all();

    // For March: March first (₦100,000), then January (₦100,000), then ₦20,000 of February.
    $payment = confirmedPayment($this->acme, $this->owner, $this->kemi, '220,000', ['billId' => (string) $march->id]);
    expect(allocationsOf($this->acme, $payment))->toBe([$march->id => 10_000_000, $january->id => 10_000_000, $february->id => 2_000_000])
        ->and(storedBill($this->acme, $january)->status)->toBe(BillStatus::Paid)
        ->and(storedBill($this->acme, $february)->status)->toBe(BillStatus::Overdue)
        ->and(storedBill($this->acme, $february)->paid_kobo->kobo)->toBe(2_000_000)
        ->and(storedBill($this->acme, $march)->status)->toBe(BillStatus::Paid)
        ->and(creditOf($this->acme, $this->kemi)->kobo)->toBe(0);

    // Without a bill: February's ₦80,000, then ₦5,000 to credit.
    $second = confirmedPayment($this->acme, $this->owner, $this->kemi, '85,000');
    expect(allocationsOf($this->acme, $second))->toBe([$february->id => 8_000_000])
        ->and(storedBill($this->acme, $february)->status)->toBe(BillStatus::Paid)
        ->and(creditOf($this->acme, $this->kemi)->kobo)->toBe(500_000)
        ->and(creditEntriesOf($this->acme, $this->kemi))->toBe([['overpayment', 500_000, null]]);
});

it('confirms a payment with an explicit split across bills, the rest to credit, and refuses a split that does not fit', function () {
    $this->travelTo('2027-01-25 09:00:00');
    runBillGeneration();
    [$january, $february] = billsOfTenant($this->acme, $this->kemi)->all();
    $other = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348030000002', 'email' => 'tunde@example.test']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 2']), $other, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    runBillGeneration();
    $othersBill = billsOfTenant($this->acme, $other)->first();

    $refusals = [
        'more than the bill owes' => [$january->id => '100,001'],
        'more than the payment' => [$january->id => '100,000', $february->id => '60,000'],
        'another tenant\'s bill' => [$othersBill->id => '10,000'],
        'an unknown bill' => [999_999 => '10,000'],
        'nothing' => [$january->id => '0'],
    ];

    foreach ($refusals as $case => $split) {
        $payment = recordPayment($this->acme, $this->owner, $this->kemi, '150,000');
        expect(fn () => confirmPayment($this->acme, $this->owner, $payment, $split))->toThrow(PaymentRefused::class, null, $case)
            ->and(storedPayment($this->acme, $payment)->status)->toBe(PaymentStatus::PendingConfirmation);
    }

    expect(storedBill($this->acme, $january)->paid_kobo->kobo)->toBe(0)
        ->and(inOrganization($this->acme, fn () => PaymentAllocation::query()->count()))->toBe(0);

    $payment = recordPayment($this->acme, $this->owner, $this->kemi, '150,000');
    confirmPayment($this->acme, $this->owner, $payment, [$february->id => '40,000', $january->id => '100,000']);

    expect(allocationsOf($this->acme, $payment))->toEqual([$february->id => 4_000_000, $january->id => 10_000_000])
        ->and(storedBill($this->acme, $january)->status)->toBe(BillStatus::Paid)
        ->and(storedBill($this->acme, $february)->status)->toBe(BillStatus::PartiallyPaid)
        ->and(creditOf($this->acme, $this->kemi)->kobo)->toBe(1_000_000);
});

it('acceptance: a void bill accepts nothing, whichever way a payment is pointed at it', function () {
    $this->travelTo('2027-01-25 09:00:00');
    runBillGeneration();
    [$january, $february] = billsOfTenant($this->acme, $this->kemi)->all();
    // A payment recorded for January, which is then voided.
    $pending = recordPayment($this->acme, $this->owner, $this->kemi, '100,000', ['billId' => (string) $january->id]);
    inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, storedBill($this->acme, $january), 'Entered twice'));

    // Recording a new payment for it is refused.
    expect(fn () => recordPayment($this->acme, $this->owner, $this->kemi, '100,000', ['billId' => (string) $january->id]))
        ->toThrow(ValidationException::class, 'Bill '.$january->number.' is void and accepts no payment.');

    // An explicit split to it is refused.
    expect(fn () => confirmPayment($this->acme, $this->owner, $pending, [$january->id => '100,000']))
        ->toThrow(PaymentRefused::class, 'Bill '.$january->number.' is void and accepts no payment.');

    // Confirming the payment recorded for it skips it: February is paid instead.
    confirmPayment($this->acme, $this->owner, $pending);
    expect(allocationsOf($this->acme, $pending))->toBe([$february->id => 10_000_000]);

    // With nothing else open, everything goes to credit.
    $more = confirmedPayment($this->acme, $this->owner, $this->kemi, '30,000');
    expect(allocationsOf($this->acme, $more))->toBe([])
        ->and(creditOf($this->acme, $this->kemi)->kobo)->toBe(3_000_000)
        ->and(storedBill($this->acme, $january)->status)->toBe(BillStatus::Void)
        ->and(storedBill($this->acme, $january)->paid_kobo->kobo)->toBe(0);

    // Credit is never applied to a void bill either.
    expect(inOrganization($this->acme, fn () => app(TenantCredit::class)->applyToBill($january->id)))->toBeNull()
        ->and(storedBill($this->acme, $january)->paid_kobo->kobo)->toBe(0)
        ->and(creditOf($this->acme, $this->kemi)->kobo)->toBe(3_000_000);
});

it('applies credit to a bill exactly once, however often the bill is announced', function () {
    confirmedPayment($this->acme, $this->owner, $this->kemi, '150,000');
    expect(creditOf($this->acme, $this->kemi)->kobo)->toBe(5_000_000);

    $this->travelTo('2027-01-25 09:00:00');
    runBillGeneration();
    $february = billsOfTenant($this->acme, $this->kemi)->last();
    expect(storedBill($this->acme, $february)->paid_kobo->kobo)->toBe(5_000_000);

    // The same BillIssued handled again (a queue retry, a replay), and the service called again.
    $event = new BillIssued(...BillService::eventIds(storedBill($this->acme, $february)));
    app(ApplyCreditToNewBill::class)->handle($event);
    event($event);
    // More credit arrives later: the bill already had its share of credit.
    inOrganization($this->acme, function () use ($february): void {
        DB::transaction(fn () => app(TenantCredit::class)->addOverpayment(Payment::query()->firstOrFail(), Money::naira(20_000), null));
        expect(app(TenantCredit::class)->applyToBill($february->id))->toBeNull();
    });

    expect(storedBill($this->acme, $february)->paid_kobo->kobo)->toBe(5_000_000)
        ->and(inOrganization($this->acme, fn () => TenantCreditEntry::query()->where('bill_id', $february->id)->count()))->toBe(1)
        ->and(AuditEntry::query()->where('event', 'credit.applied')->count())->toBe(1);

    // The database refuses a second application to the bill too.
    expect(fn () => inOrganization($this->acme, function () use ($february): void {
        $entry = new TenantCreditEntry;
        $entry->forceFill(['tenant_profile_id' => $this->kemi->id, 'amount_kobo' => Money::naira(-1), 'reason' => 'applied_to_bill', 'bill_id' => $february->id, 'applied_bill_id' => $february->id])->save();
    }))->toThrow(UniqueConstraintViolationException::class);
});

it('keeps the credit balance equal to the sum of the tenant\'s credit entries', function () {
    confirmedPayment($this->acme, $this->owner, $this->kemi, '175,000');
    confirmedPayment($this->acme, $this->owner, $this->kemi, '12,345.67');

    foreach (['2027-01-25', '2027-02-22', '2027-03-25'] as $day) {
        $this->travelTo($day.' 09:00:00');
        runBillGeneration();
    }

    $sum = inOrganization($this->acme, fn (): int => (int) TenantCreditEntry::query()->where('tenant_profile_id', $this->kemi->id)->sum('amount_kobo'));
    expect(creditOf($this->acme, $this->kemi)->kobo)->toBe($sum)
        ->and($sum)->toBe(7_500_000 + 1_234_567 - 8_734_567)
        ->and(inOrganization($this->acme, fn () => TenantCreditBalance::query()->count()))->toBe(1);
});

it('confirms a payment once: a second confirmation or a rejection afterwards is refused and changes nothing', function () {
    $confirmed = [];
    Event::listen(PaymentConfirmed::class, function (PaymentConfirmed $event) use (&$confirmed): void {
        $confirmed[] = $event;
    });
    $payment = recordPayment($this->acme, $this->owner, $this->kemi, '120,000', ['reference' => 'FBN/TRF/0042']);
    confirmPayment($this->acme, $this->owner, $payment, note: 'Seen on the statement');

    // Past the policy (as if the page was opened before): the service checks under the lock.
    Gate::before(fn () => true);
    expect(fn () => confirmPayment($this->acme, $this->owner, $payment))->toThrow(PaymentRefused::class, 'This payment is already confirmed.')
        ->and(fn () => inOrganization($this->acme, fn () => app(PaymentService::class)->reject($this->owner, $payment, 'Duplicate')))->toThrow(PaymentRefused::class, 'This payment is already confirmed.');

    $stored = storedPayment($this->acme, $payment);
    $audit = AuditEntry::query()->where('event', 'payment.confirmed')->sole();
    expect($stored->status)->toBe(PaymentStatus::Confirmed)
        ->and($stored->confirmed_by_user_id)->toBe($this->owner->id)
        ->and($stored->confirmed_at?->toDateTimeString())->toBe('2026-12-25 09:00:00')
        ->and($stored->confirmation_note)->toBe('Seen on the statement')
        ->and(allocationsOf($this->acme, $payment))->toBe([$this->january->id => 10_000_000])
        ->and(creditOf($this->acme, $this->kemi)->kobo)->toBe(2_000_000)
        ->and($audit->log_name)->toBe('payments')
        ->and($audit->subject_id)->toBe($payment->id)
        ->and($audit->causer_id)->toBe($this->owner->id)
        ->and($audit->properties['amount_kobo'])->toBe(12_000_000)
        ->and($audit->properties['credit_kobo'])->toBe(2_000_000)
        ->and($audit->properties['allocations'])->toHaveCount(1)
        ->and($audit->properties['allocations'][0]['bill_id'])->toBe($this->january->id)
        ->and($audit->properties['allocations'][0]['amount_kobo'])->toBe(10_000_000)
        ->and($confirmed)->toHaveCount(1)
        ->and($confirmed[0]->paymentId)->toBe($payment->id)
        ->and($confirmed[0]->organizationId)->toBe($this->acme->id)
        ->and($confirmed[0]->tenantProfileId)->toBe($this->kemi->id)
        ->and($confirmed[0]->amountKobo)->toBe(12_000_000)
        ->and($confirmed[0]->method)->toBe('bank_transfer')
        ->and($confirmed[0]->source)->toBe('manual')
        ->and($confirmed[0]->confirmedByUserId)->toBe($this->owner->id)
        ->and($confirmed[0]->allocations)->toBe([['billId' => $this->january->id, 'amountKobo' => 10_000_000]])
        ->and($confirmed[0]->creditKobo)->toBe(2_000_000);
});

it('rejects a payment with a reason, audited, and allocates nothing', function () {
    $rejected = [];
    Event::listen(PaymentRejected::class, function (PaymentRejected $event) use (&$rejected): void {
        $rejected[] = $event;
    });
    $payment = recordPayment($this->acme, $this->owner, $this->kemi, '100,000');

    expect(fn () => inOrganization($this->acme, fn () => app(PaymentService::class)->reject($this->owner, $payment, '  ')))->toThrow(ValidationException::class);

    inOrganization($this->acme, fn () => app(PaymentService::class)->reject($this->owner, $payment, 'Not on the   bank statement'));

    $stored = storedPayment($this->acme, $payment);
    $audit = AuditEntry::query()->where('event', 'payment.rejected')->sole();
    expect($stored->status)->toBe(PaymentStatus::Rejected)
        ->and($stored->rejection_reason)->toBe('Not on the bank statement')
        ->and($stored->rejected_by_user_id)->toBe($this->owner->id)
        ->and(allocationsOf($this->acme, $payment))->toBe([])
        ->and(storedBill($this->acme, $this->january)->paid_kobo->kobo)->toBe(0)
        ->and(creditOf($this->acme, $this->kemi)->kobo)->toBe(0)
        ->and($audit->properties['reason'])->toBe('Not on the bank statement')
        ->and($audit->causer_id)->toBe($this->owner->id)
        ->and($rejected)->toHaveCount(1)
        ->and($rejected[0]->reason)->toBe('Not on the bank statement');

    // A rejected payment is not confirmed afterwards.
    Gate::before(fn () => true);
    expect(fn () => confirmPayment($this->acme, $this->owner, $payment))->toThrow(PaymentRefused::class, 'This payment was rejected.');
});

it('sub: never deletes or edits a confirmed payment, its allocations or credit entries', function () {
    $payment = confirmedPayment($this->acme, $this->owner, $this->kemi, '150,000');

    inOrganization($this->acme, function () use ($payment): void {
        $stored = Payment::query()->findOrFail($payment->id);

        expect(fn () => $stored->delete())->toThrow(LogicException::class, 'never deleted')
            ->and(fn () => Payment::query()->findOrFail($payment->id)->forceDelete())->toThrow(LogicException::class, 'never deleted')
            ->and(fn () => Payment::query()->findOrFail($payment->id)->forceFill(['amount_kobo' => Money::naira(1)])->save())->toThrow(LogicException::class, 'never changed')
            ->and(fn () => Payment::query()->findOrFail($payment->id)->forceFill(['status' => PaymentStatus::PendingConfirmation])->save())->toThrow(LogicException::class, 'never changed')
            ->and(fn () => Payment::query()->findOrFail($payment->id)->forceFill(['tenant_profile_id' => 1])->save())->toThrow(LogicException::class, 'never changed')
            ->and(fn () => PaymentAllocation::query()->firstOrFail()->delete())->toThrow(LogicException::class)
            ->and(fn () => PaymentAllocation::query()->firstOrFail()->forceFill(['amount_kobo' => Money::naira(1)])->save())->toThrow(LogicException::class)
            ->and(fn () => TenantCreditEntry::query()->firstOrFail()->delete())->toThrow(LogicException::class)
            ->and(fn () => TenantCreditBalance::query()->firstOrFail()->delete())->toThrow(LogicException::class);

        // A receipt may still be attached (P03-10b).
        $stored->forceFill(['receipt_id' => 42])->save();

        // The policy offers no edit or delete, to anyone.
        expect(Gate::forUser($this->owner)->allows('delete', $stored))->toBeFalse()
            ->and(Gate::forUser($this->owner)->allows('update', $stored))->toBeFalse()
            ->and(app(PaymentPolicy::class)->delete($this->owner, $stored))->toBeFalse();

        // The database refuses to drop the payment under its allocations.
        expect(fn () => DB::table('payments')->where('id', $payment->id)->delete())->toThrow(QueryException::class);
    });

    $stored = storedPayment($this->acme, $payment);
    expect($stored->trashed())->toBeFalse()
        ->and($stored->amount_kobo->kobo)->toBe(15_000_000)
        ->and($stored->receipt_id)->toBe(42)
        ->and(allocationsOf($this->acme, $payment))->toBe([$this->january->id => 10_000_000]);

    // No route deletes or edits a payment.
    expect(collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'payments') && array_diff($route->methods(), ['GET', 'HEAD']) !== [])->all())->toBe([]);
});

it('checks a recorded payment: amount, method, date, note for cash, the tenant and the bill', function () {
    $cases = [
        'no amount' => [['amount' => ''], 'amount'],
        'not an amount' => [['amount' => '1.234'], 'amount'],
        'zero' => [['amount' => '0'], 'amount'],
        'negative' => [['amount' => '-5,000'], 'amount'],
        'Paystack by hand' => [['method' => 'paystack'], 'method'],
        'in the future' => [['paidAt' => '2026-12-26'], 'paidAt'],
        'not a date' => [['paidAt' => '25/12/2026'], 'paidAt'],
        'cash without a note' => [['method' => 'cash'], 'note'],
        'POS without a note' => [['method' => 'pos', 'note' => '  '], 'note'],
        'unknown tenant' => [['tenantId' => '999999'], 'tenantId'],
        'unknown bill' => [['billId' => '999999'], 'billId'],
    ];

    foreach ($cases as $case => [$changes, $field]) {
        try {
            recordPayment($this->acme, $this->owner, $this->kemi, '100,000', $changes);
            $this->fail("{$case} was accepted.");
        } catch (ValidationException $refused) {
            expect($refused->errors())->toHaveKey($field);
        }
    }

    // Another tenant's bill.
    $other = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348030000002', 'email' => 'tunde@example.test']);
    expect(fn () => recordPayment($this->acme, $this->owner, $other, '100,000', ['billId' => (string) $this->january->id]))->toThrow(ValidationException::class, 'Choose one of the tenant\'s bills.');

    $cash = recordPayment($this->acme, $this->owner, $this->kemi, '₦1,500.50', ['method' => 'cash', 'note' => 'Received at the estate office by Musa', 'payerName' => 'Kemi\'s  employer', 'payerType' => 'corporate', 'paidAt' => '2026-12-24']);
    $stored = storedPayment($this->acme, $cash);
    $audit = AuditEntry::query()->where('event', 'payment.recorded')->where('subject_id', $cash->id)->sole();
    expect(paymentsOf($this->acme))->toHaveCount(1)
        ->and($stored->amount_kobo->kobo)->toBe(150_050)
        ->and($stored->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and($stored->source->value)->toBe('manual')
        ->and($stored->payer_name)->toBe('Kemi\'s employer')
        ->and($stored->payer_type->value)->toBe('corporate')
        ->and($stored->paid_at->toDateString())->toBe('2026-12-24')
        ->and($stored->recorded_by_user_id)->toBe($this->owner->id)
        ->and($audit->causer_id)->toBe($this->owner->id)
        ->and($audit->properties['amount_kobo'])->toBe(150_050)
        ->and(storedBill($this->acme, $this->january)->paid_kobo->kobo)->toBe(0);
});

it('keeps money in kobo, to the last kobo of credit', function () {
    $payment = confirmedPayment($this->acme, $this->owner, $this->kemi, '100,000.01');
    expect(storedPayment($this->acme, $payment)->amount_kobo->kobo)->toBe(10_000_001)
        ->and(creditOf($this->acme, $this->kemi)->kobo)->toBe(1)
        ->and(inOrganization($this->acme, fn () => Bill::query()->sole()->paid_kobo->kobo))->toBe(10_000_000);
});
