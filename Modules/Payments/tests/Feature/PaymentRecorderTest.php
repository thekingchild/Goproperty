<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Enums\BillStatus;
use Modules\Payments\Contracts\PaymentDraft;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentRecorder;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Events\PaymentConfirmed;

require_once __DIR__.'/helpers.php';

/*
 * P03-10a: Contracts\PaymentRecorder, how Collections (bank statement
 * matching, P03-07b) and OnlinePayments (the organization's own Paystack,
 * P03-08) record payments: idempotent by key (AGENT.md § 3 rule 6), the
 * same rules, locks, audit and events as a member's confirmation.
 */

beforeEach(function () {
    holdInvoicePdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348030000001', 'email' => 'kemi@example.test']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $palm, ['unit_label' => 'Flat 1']), $this->kemi, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $palm, ['unit_label' => 'Shop 1']), $this->kemi, ['billingCycle' => 'monthly', 'rent' => '40,000', 'deposit' => '0']);
    runBillGeneration();
    [$this->flat, $this->shop] = billsOfTenant($this->acme, $this->kemi)->sortByDesc(fn ($bill): int => $bill->amount_kobo->kobo)->values()->all();
});

afterEach(fn () => billsFreshRequest());

/** A draft from a source, with changes. */
function draftFor(int $tenantId, string $key, int $naira, array $changes = []): PaymentDraft
{
    return new PaymentDraft(...$changes + [
        'tenantProfileId' => $tenantId,
        'amount' => Money::naira($naira),
        'method' => PaymentMethod::BankTransfer,
        'source' => PaymentSource::StatementMatch,
        'idempotencyKey' => $key,
        'paidAt' => CarbonImmutable::parse('2026-12-24 15:00:00'),
    ]);
}

it('records a payment once per idempotency key, whatever the replay says', function () {
    $recorder = fn () => app(PaymentRecorder::class);
    $first = inOrganization($this->acme, fn () => $recorder()->record(draftFor($this->kemi->id, 'statement-line:41', 100_000, ['payerName' => 'KEMI ADEBAYO', 'reference' => 'TRF GP-ABC'])));
    $again = inOrganization($this->acme, fn () => $recorder()->record(draftFor($this->kemi->id, 'statement-line:41', 999_999)));

    $payment = paymentsOf($this->acme)->sole();
    expect($first->created)->toBeTrue()
        ->and($again->created)->toBeFalse()
        ->and($again->paymentId)->toBe($first->paymentId)
        ->and($again->amount->kobo)->toBe(10_000_000)
        ->and($payment->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and($payment->source->value)->toBe('statement_match')
        ->and($payment->idempotency_key)->toBe('statement-line:41')
        ->and($payment->paid_at->toDateString())->toBe('2026-12-24')
        ->and($payment->recorded_by_user_id)->toBeNull()
        ->and(AuditEntry::query()->where('event', 'payment.recorded')->count())->toBe(1);
});

it('records and confirms a verified payment as the system, once, settling the bill it was for first', function () {
    $events = [];
    Event::listen(PaymentConfirmed::class, function (PaymentConfirmed $event) use (&$events): void {
        $events[] = $event;
    });
    $draft = draftFor($this->kemi->id, 'paystack:T-1001', 150_000, [
        'method' => PaymentMethod::Paystack,
        'source' => PaymentSource::PaystackWebhook,
        'billId' => $this->shop->id,
        'paystackReference' => 'T-1001',
    ]);

    $recorded = inOrganization($this->acme, fn () => app(PaymentRecorder::class)->recordConfirmed($draft));
    // A replayed webhook, under another key but the same Paystack reference too.
    $replay = inOrganization($this->acme, fn () => app(PaymentRecorder::class)->recordConfirmed($draft));
    $other = inOrganization($this->acme, fn () => app(PaymentRecorder::class)->recordConfirmed(draftFor($this->kemi->id, 'paystack:other-key', 150_000, ['paystackReference' => 'T-1001'])));

    $payment = paymentsOf($this->acme)->sole();
    $audit = AuditEntry::query()->where('event', 'payment.confirmed')->sole();
    expect($recorded->created)->toBeTrue()
        ->and($recorded->isConfirmed())->toBeTrue()
        ->and($recorded->allocations)->toEqual([$this->shop->id => Money::naira(40_000), $this->flat->id => Money::naira(100_000)])
        ->and($recorded->credit->kobo)->toBe(1_000_000)
        ->and($replay->created)->toBeFalse()
        ->and($replay->paymentId)->toBe($payment->id)
        ->and($other->paymentId)->toBe($payment->id)
        ->and($payment->confirmed_by_user_id)->toBeNull()
        ->and($payment->paystack_reference)->toBe('T-1001')
        ->and($audit->causer_id)->toBeNull()
        ->and(storedBill($this->acme, $this->shop)->status)->toBe(BillStatus::Paid)
        ->and(storedBill($this->acme, $this->flat)->status)->toBe(BillStatus::Paid)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->confirmedByUserId)->toBeNull()
        ->and($events[0]->source)->toBe('paystack_webhook');
});

it('confirms a payment recorded earlier under the same key, with a member\'s explicit split', function () {
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    inOrganization($this->acme, fn () => app(PaymentRecorder::class)->record(draftFor($this->kemi->id, 'statement-line:7', 70_000)));

    $confirmed = inOrganization($this->acme, fn () => app(PaymentRecorder::class)->recordConfirmed(draftFor($this->kemi->id, 'statement-line:7', 70_000, [
        'allocations' => [$this->flat->id => Money::naira(50_000), $this->shop->id => Money::naira(20_000)],
        'by' => $agent,
    ])));

    expect($confirmed->created)->toBeFalse()
        ->and($confirmed->isConfirmed())->toBeTrue()
        ->and($confirmed->allocations)->toEqual([$this->flat->id => Money::naira(50_000), $this->shop->id => Money::naira(20_000)])
        ->and($confirmed->credit->kobo)->toBe(0)
        ->and(paymentsOf($this->acme)->sole()->confirmed_by_user_id)->toBe($agent->id)
        ->and(AuditEntry::query()->where('event', 'payment.confirmed')->sole()->causer_id)->toBe($agent->id);

    // A split that does not fit records nothing.
    expect(fn () => inOrganization($this->acme, fn () => app(PaymentRecorder::class)->recordConfirmed(draftFor($this->kemi->id, 'statement-line:8', 70_000, [
        'allocations' => [$this->flat->id => Money::naira(60_000)],
        'by' => $agent,
    ]))))->toThrow(ValidationException::class, 'owes ₦50,000');
    expect(paymentsOf($this->acme))->toHaveCount(1);
});

it('refuses a draft that is not a payment of this organization\'s tenant', function () {
    $cases = [
        'no key' => draftFor($this->kemi->id, '  ', 1_000),
        'nothing paid' => draftFor($this->kemi->id, 'k-1', 0),
        'an unknown tenant' => draftFor(999_999, 'k-2', 1_000),
        'an unknown bill' => draftFor($this->kemi->id, 'k-3', 1_000, ['billId' => 999_999]),
    ];

    foreach ($cases as $case => $draft) {
        expect(fn () => inOrganization($this->acme, fn () => app(PaymentRecorder::class)->recordConfirmed($draft)))->toThrow(ValidationException::class, null, $case);
    }

    expect(paymentsOf($this->acme))->toHaveCount(0);
});
