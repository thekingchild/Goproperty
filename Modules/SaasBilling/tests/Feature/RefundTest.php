<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\SubscriptionStatus;
use App\Models\ApprovalRequest;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Platform\Approvals\ApprovalRefused;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Approvals\ApprovalStatus;
use App\Platform\Billing\PlatformInvoiceRecord;
use App\Platform\Billing\PlatformPaymentRecord;
use App\Platform\Billing\PlatformRefundRecord;
use App\Platform\Billing\Refunds;
use App\Platform\Filament\Pages\Settings\RefundSettings;
use App\Platform\Filament\Resources\PlatformInvoices\Pages\ListPlatformInvoices;
use App\Platform\Filament\Resources\PlatformInvoices\Pages\ViewPlatformInvoice;
use App\Platform\Filament\Resources\PlatformPayments\Pages\ListPlatformPayments;
use App\Platform\Filament\Resources\PlatformPayments\Pages\ViewPlatformPayment;
use App\Platform\Filament\Resources\PlatformPayments\RelationManagers\RefundsRelationManager;
use App\Settings\SettingsStore;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\SaasBilling\Contracts\InvoiceEmailRefused;
use Modules\SaasBilling\Contracts\InvoiceEmails;
use Modules\SaasBilling\Contracts\PlatformRefunds;
use Modules\SaasBilling\Contracts\RefundRefused;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Enums\PlatformPaymentStatus;
use Modules\SaasBilling\Enums\RefundStatus;
use Modules\SaasBilling\Metering\SubscriptionMetering;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\PlatformPayment;
use Modules\SaasBilling\Models\PlatformRefund;
use Modules\SaasBilling\Notifications\PlatformInvoiceIssued;
use Modules\SaasBilling\Notifications\PlatformRefundIssued;
use Modules\SaasBilling\Refunds\PlatformRefunds as RefundService;

require_once __DIR__.'/../../../../tests/Support/plans.php';
require_once __DIR__.'/../../../../tests/Support/organization-settings.php';
require_once __DIR__.'/../../../../tests/Feature/Exports/helpers.php';
require_once __DIR__.'/metering-helpers.php';
require_once __DIR__.'/payment-helpers.php';

/*
 * P06-07b: the console's invoices and payments, and refunds of GoProperty's
 * own income (BILLING_PAYMENTS.md § 8; PLATFORM_CONSOLE.md § 1 two-person
 * rules; docs/plans/P06-subtasks.md defaults 55–59). Acceptance of P06-07:
 * "Every page policy-checked per staff role; refunds need the configured
 * approval" — Paystack simulated with its documented answers.
 *
 * The world: Acme on Starter with 30 units; its trial ended on 16 November,
 * invoice PR-2026-000001 for 16–30 November (₦12,900, due 23 November),
 * paid through Paystack on 20 November (PINV-PR-2026-000001-a1).
 */

const REFUND_REFERENCE = 'PINV-PR-2026-000001-a1';

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    seedStaffRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Africa/Lagos'));
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd', 'status' => OrganizationStatus::Active]);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada@adeyemi.ng']), $this->acme);
    $this->admin = joinAs(User::factory()->create(['name' => 'Bisi Admin']), $this->acme, OrganizationRole::Admin);
    withUnits($this->acme, 30);
    startTrial($this->acme, 'starter', 14);
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:30', 'Africa/Lagos'));
    endTrials();

    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'Africa/Lagos'));
    subscriptionPaystack([REFUND_REFERENCE => ['success', FIRST_INVOICE_KOBO]]);
    payInvoice($this->owner)->assertOk();
    subscriptionWebhook(REFUND_REFERENCE)->assertOk();
    expect(invoiceNumbered($this->acme, FIRST_INVOICE)->status)->toBe(InvoiceStatus::Paid);

    $this->funmi = staffNamed('Funmi', ['finance-admin']);
    $this->kemi = staffNamed('Kemi', ['finance-admin']);
    $this->travelTo(CarbonImmutable::parse('2026-11-25 11:00', 'Africa/Lagos'));
});

afterEach(fn () => exportsFreshRequest());

/**
 * GoProperty's Paystack answering refunds: POST /refund accepts with id
 * 9001 (status $accepted, or the HTTP $refusal), GET /refund/9001 answers
 * $later (null: not found).
 */
function refundPaystack(string $accepted = 'pending', ?string $later = 'processing', ?int $refusal = null): Factory
{
    withPaystackKey();

    return fakePaystack([
        'api.paystack.co/refund/*' => fn () => $later === null
            ? Http::response(['status' => false, 'message' => 'Refund not found'], 404)
            : Http::response(['status' => true, 'message' => 'Refund retrieved', 'data' => ['id' => 9001, 'status' => $later, 'amount' => FIRST_INVOICE_KOBO, 'currency' => 'NGN']]),
        'api.paystack.co/refund' => fn (Request $request) => $refusal !== null
            ? Http::response(['status' => false, 'message' => 'Transaction has been fully reversed'], $refusal)
            : Http::response(['status' => true, 'message' => 'Refund has been queued for processing', 'data' => [
                'id' => 9001, 'status' => $accepted, 'amount' => $request['amount'], 'currency' => 'NGN',
                'transaction' => ['reference' => $request['transaction']],
            ]]),
    ]);
}

function firstPayment(Organization $organization): PlatformPayment
{
    return invoicePayments($organization, FIRST_INVOICE)[0];
}

function paymentRecord(int $id): PlatformPaymentRecord
{
    return PlatformPaymentRecord::withDetails(PlatformPaymentRecord::query())->findOrFail($id);
}

/** @return list<PlatformRefund> */
function refundsOf(Organization $organization): array
{
    return inOrganization($organization, fn (): array => array_values(PlatformRefund::query()->orderBy('id')->get()->all()));
}

function consoleAs(PlatformStaff $staff): void
{
    Filament::setCurrentPanel(Filament::getPanel('console'));
    test()->flushSession();
    test()->actingAs($staff, 'staff');
}

function refundApprovalAbove(int $naira): void
{
    app(SettingsStore::class)->set(RefundService::APPROVAL_SETTING, $naira * 100);
}

it('acceptance: a refund at or below the configured amount runs at once through Paystack; refunding the whole payment opens the invoice again', function () {
    $http = refundPaystack();
    $payment = firstPayment($this->acme);
    consoleAs($this->funmi);

    // ₦12,900 is below the default ₦100,000.
    Livewire::test(ListPlatformPayments::class)
        ->callTableAction('refund', $payment->id, ['amount' => '12,900', 'reason' => 'Charged twice: also paid by transfer'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Paystack is refunding ₦12,900. The owners were told.');

    $http->assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://api.paystack.co/refund'
        && $request['transaction'] === REFUND_REFERENCE && $request['amount'] === FIRST_INVOICE_KOBO
        && $request->hasHeader('Authorization', 'Bearer sk_test_goproperty_platform'));

    [$refund] = refundsOf($this->acme);
    $invoice = invoiceNumbered($this->acme, FIRST_INVOICE);
    expect($refund->only(['status', 'paystack_refund_id', 'executed_by_staff_id', 'requested_by_staff_id', 'approval_request_id', 'invoice_reopened']))
        ->toEqual(['status' => RefundStatus::Processing, 'paystack_refund_id' => '9001', 'executed_by_staff_id' => $this->funmi->id, 'requested_by_staff_id' => $this->funmi->id, 'approval_request_id' => null, 'invoice_reopened' => true])
        ->and($refund->amount_kobo->kobo)->toBe(FIRST_INVOICE_KOBO)
        // Owed again, due 7 days after the refund; the payment itself never changes.
        ->and($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->paid_at)->toBeNull()
        ->and($invoice->due_date->toDateString())->toBe('2026-12-02')
        ->and(firstPayment($this->acme)->status)->toBe(PlatformPaymentStatus::Confirmed)
        ->and(ApprovalRequest::query()->count())->toBe(0);

    $entry = AuditEntry::query()->where('log_name', SubscriptionMetering::LOG)->where('event', 'platform_refund.issued')->sole();
    expect($entry->causer?->is($this->funmi))->toBeTrue()
        ->and($entry->properties['invoice_reopened'])->toBeTrue()
        ->and($entry->properties['amount_kobo'])->toBe(FIRST_INVOICE_KOBO)
        ->and($entry->properties['organization_id'])->toBe($this->acme->id);

    // The owners are told; the email says how the money comes back and that the invoice is open again.
    Notification::assertSentToTimes($this->owner, PlatformRefundIssued::class, 1);
    Notification::assertNotSentTo($this->admin, PlatformRefundIssued::class);
    $mail = (string) (new PlatformRefundIssued($this->acme->id, $refund->id))->toMail($this->owner)->render();
    expect($mail)->toContain('GoProperty refunded ₦12,900 to Adeyemi Realty Ltd on 25 November 2026')
        ->and($mail)->toContain('Invoice '.FIRST_INVOICE.' is open again, due 2 December 2026');

    // Nothing is left to refund.
    expect(fn () => app(PlatformRefunds::class)->refundNow($this->funmi, $payment->id, 100, 'Once more'))
        ->toThrow(RefundRefused::class, 'Nothing is left to refund: the ₦12,900 this payment took is refunded or waiting for approval already.');

    // The owner can pay the reopened invoice again: a new attempt.
    exportsFreshRequest();
    subscriptionPaystack(['PINV-'.FIRST_INVOICE.'-a2' => ['success', FIRST_INVOICE_KOBO]]);
    payInvoice($this->owner)->assertOk();
    expect(invoicePayments($this->acme, FIRST_INVOICE))->toHaveCount(2)
        ->and(invoicePayments($this->acme, FIRST_INVOICE)[1]->paystack_reference)->toBe('PINV-'.FIRST_INVOICE.'-a2');
});

it('acceptance: a refund above the configured amount waits for a second staff member, never the requester, and runs on approval', function () {
    refundApprovalAbove(10_000);
    $http = refundPaystack();
    $payment = firstPayment($this->acme);
    consoleAs($this->funmi);

    Livewire::test(ListPlatformPayments::class)
        ->callTableAction('refund', $payment->id, ['amount' => '12900', 'reason' => 'Customer left in the first week'])
        ->assertNotified('Above ₦10,000: the refund waits for a second staff member\'s approval in Staff → Approvals.');

    // Nothing went to Paystack; the refund waits and holds its amount back.
    $http->assertNothingSent();
    [$refund] = refundsOf($this->acme);
    $request = ApprovalRequest::query()->sole();
    expect($refund->status)->toBe(RefundStatus::Requested)
        ->and($refund->approval_request_id)->toBe($request->id)
        ->and($request->only(['status', 'requested_by_staff_id', 'reason']))->toEqual(['status' => ApprovalStatus::Pending, 'requested_by_staff_id' => $this->funmi->id, 'reason' => 'Customer left in the first week'])
        ->and($request->summary)->toBe('Refund ₦12,900 of '.REFUND_REFERENCE.' (Adeyemi Realty Ltd, invoice '.FIRST_INVOICE.')')
        ->and($request->payload['refund_id'])->toBe($refund->id)
        ->and(invoiceNumbered($this->acme, FIRST_INVOICE)->status)->toBe(InvoiceStatus::Paid)
        ->and(app(PlatformRefunds::class)->refundable($payment->id)->remaining()->kobo)->toBe(0);
    Notification::assertNotSentTo($this->owner, PlatformRefundIssued::class);

    // The requester cannot approve it; nor can staff without `refunds.approve`, nor can the contract be called around it.
    $approvals = app(ApprovalService::class);
    expect(fn () => $approvals->approve($request, $this->funmi))->toThrow(ApprovalRefused::class, 'You cannot decide your own request')
        ->and(fn () => $approvals->approve($request, staffNamed('Opeyemi', ['operations-manager'])))->toThrow(ApprovalRefused::class, 'permission')
        ->and(fn () => app(PlatformRefunds::class)->refundNow($this->funmi, $payment->id, FIRST_INVOICE_KOBO, 'Around the approval'))->toThrow(RefundRefused::class)
        ->and(fn () => app(PlatformRefunds::class)->approve($this->funmi, $refund->id, $request->id))->toThrow(RefundRefused::class, 'your own refund');
    $http->assertNothingSent();

    // A second finance admin approves: the refund runs.
    $approvals->approve($request->fresh() ?? $request, $this->kemi, 'Checked with the customer');

    $refund = refundsOf($this->acme)[0];
    expect($refund->status)->toBe(RefundStatus::Processing)
        ->and($refund->executed_by_staff_id)->toBe($this->kemi->id)
        ->and($refund->invoice_reopened)->toBeTrue()
        ->and(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Approved)
        ->and(invoiceNumbered($this->acme, FIRST_INVOICE)->status)->toBe(InvoiceStatus::Open);
    $http->assertSentCount(1);
    expect(AuditEntry::query()->where('event', 'platform_refund.issued')->sole()->causer?->is($this->kemi))->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'platform_refund.requested')->sole()->causer?->is($this->funmi))->toBeTrue();
    Notification::assertSentToTimes($this->owner, PlatformRefundIssued::class, 1);
});

it('counts earlier refunds against the approval amount, so splitting a refund never avoids it', function () {
    refundApprovalAbove(10_000);
    refundPaystack();
    $payment = firstPayment($this->acme);

    expect(app(Refunds::class)->request($this->funmi, paymentRecord($payment->id), 600_000, 'Goodwill for the outage'))->toContain('Paystack is refunding ₦6,000');
    // ₦6,000 more makes ₦12,000 for the payment: above ₦10,000.
    expect(app(PlatformRefunds::class)->needsApproval($payment->id, 600_000))->toBeTrue()
        ->and(app(Refunds::class)->request($this->funmi, paymentRecord($payment->id), 600_000, 'More goodwill'))->toContain('waits for a second staff member');

    // A part refund leaves the invoice paid (goodwill).
    expect(invoiceNumbered($this->acme, FIRST_INVOICE)->status)->toBe(InvoiceStatus::Paid)
        ->and(collect(refundsOf($this->acme))->map(fn (PlatformRefund $refund): string => $refund->status->value)->all())->toBe(['processing', 'requested'])
        ->and(refundsOf($this->acme)[0]->invoice_reopened)->toBeFalse();
});

it('frees the amount when the approval is rejected or expires, and refunds nothing', function () {
    refundApprovalAbove(5_000);
    $http = refundPaystack();
    $payment = firstPayment($this->acme);
    $approvals = app(ApprovalService::class);

    app(Refunds::class)->request($this->funmi, paymentRecord($payment->id), FIRST_INVOICE_KOBO, 'Customer asked');
    $approvals->reject(ApprovalRequest::query()->sole(), $this->kemi, 'Not owed: they used the month');

    expect(refundsOf($this->acme)[0]->status)->toBe(RefundStatus::NotApproved)
        ->and(app(PlatformRefunds::class)->refundable($payment->id)->remaining()->kobo)->toBe(FIRST_INVOICE_KOBO)
        ->and(AuditEntry::query()->where('event', 'platform_refund.not_approved')->count())->toBe(1);

    // Asked again, and left for 7 days: expired, nothing refunded.
    app(Refunds::class)->request($this->funmi, paymentRecord($payment->id), 700_000, 'Customer asked again');
    $this->travel(8)->days();
    expect($approvals->expireStale())->toBe(1)
        ->and(refundsOf($this->acme)[1]->status)->toBe(RefundStatus::NotApproved)
        ->and(app(PlatformRefunds::class)->refundable($payment->id)->remaining()->kobo)->toBe(FIRST_INVOICE_KOBO)
        ->and(invoiceNumbered($this->acme, FIRST_INVOICE)->status)->toBe(InvoiceStatus::Paid);
    $http->assertNothingSent();
    Notification::assertNotSentTo($this->owner, PlatformRefundIssued::class);

    // A not-approved refund never changes again.
    expect(fn () => inOrganization($this->acme, fn () => tap(refundsOf($this->acme)[0], fn (PlatformRefund $refund) => $refund->forceFill(['status' => RefundStatus::Processing])->save())))
        ->toThrow(LogicException::class);
});

it('records nothing when Paystack refuses the refund or cannot be reached', function () {
    $payment = firstPayment($this->acme);

    refundPaystack(refusal: 400);
    expect(fn () => app(Refunds::class)->request($this->funmi, paymentRecord($payment->id), FIRST_INVOICE_KOBO, 'Customer asked'))
        ->toThrow(RefundRefused::class, 'Paystack did not accept the refund (400: Transaction has been fully reversed). Nothing was refunded.');

    withPaystackKey();
    fakePaystack(['api.paystack.co/refund' => fn () => throw new ConnectionException('timed out')]);
    expect(fn () => app(Refunds::class)->request($this->funmi, paymentRecord($payment->id), FIRST_INVOICE_KOBO, 'Customer asked'))
        ->toThrow(RefundRefused::class, 'Paystack could not be reached.');

    expect(refundsOf($this->acme))->toBe([])
        ->and(invoiceNumbered($this->acme, FIRST_INVOICE)->status)->toBe(InvoiceStatus::Paid)
        ->and(AuditEntry::query()->where('event', 'platform_refund.issued')->count())->toBe(0);
    Notification::assertNotSentTo($this->owner, PlatformRefundIssued::class);
});

it('refuses refunds over what is left, without a reason, of unpaid payments, and to staff without `refunds.create`', function () {
    refundPaystack();
    $payment = firstPayment($this->acme);
    $refunds = app(PlatformRefunds::class);

    expect(fn () => $refunds->refundNow($this->funmi, $payment->id, FIRST_INVOICE_KOBO + 1, 'Too much'))->toThrow(RefundRefused::class, 'between ₦0.01 and ₦12,900')
        ->and(fn () => $refunds->refundNow($this->funmi, $payment->id, 0, 'Nothing'))->toThrow(RefundRefused::class)
        ->and(fn () => $refunds->refundNow($this->funmi, $payment->id, 100, 'x'))->toThrow(RefundRefused::class, '3 to 500 characters');

    // A pending attempt took no money.
    $this->travelTo(CarbonImmutable::parse('2026-12-01 01:30', 'Africa/Lagos'));
    meterUnits();
    exportsFreshRequest();
    subscriptionPaystack();
    payInvoice($this->owner, 'PR-2026-000002')->assertOk();
    $pending = invoicePayments($this->acme, 'PR-2026-000002')[0];
    expect($pending->status)->toBe(PlatformPaymentStatus::Pending)
        ->and(fn () => $refunds->refundNow($this->funmi, $pending->id, 100, 'Not paid yet'))->toThrow(RefundRefused::class, 'This payment is pending')
        ->and(fn () => app(Refunds::class)->request($this->funmi, paymentRecord($pending->id), 100, 'Not paid yet'))->toThrow(AuthorizationException::class);

    foreach (['support-agent', 'operations-manager', 'technical-admin', 'compliance-officer', 'analyst', 'content-manager'] as $role) {
        $staff = staffNamed(str_replace('-', '', $role), [$role]);
        expect(fn () => app(Refunds::class)->request($staff, paymentRecord($payment->id), 100, 'Not allowed'))->toThrow(AuthorizationException::class)
            ->and(fn () => $refunds->refundNow($staff, $payment->id, 100, 'Not allowed'))->toThrow(RefundRefused::class, 'Request refunds');
    }

    expect(refundsOf($this->acme))->toBe([]);
});

it('refunds a held payment without touching its invoice', function () {
    // The December invoice is paid with the wrong amount: held for staff.
    $this->travelTo(CarbonImmutable::parse('2026-12-01 01:30', 'Africa/Lagos'));
    meterUnits();
    $reference = 'PINV-PR-2026-000002-a1';
    exportsFreshRequest();
    subscriptionPaystack([$reference => ['success', 1_000_000]]);
    payInvoice($this->owner, 'PR-2026-000002')->assertOk();
    subscriptionWebhook($reference, amount: 1_000_000)->assertOk();
    $held = invoicePayments($this->acme, 'PR-2026-000002')[0];
    expect($held->status)->toBe(PlatformPaymentStatus::Held);

    $http = refundPaystack();
    expect(app(PlatformRefunds::class)->refundable($held->id)->remaining()->kobo)->toBe(1_000_000);
    app(Refunds::class)->request($this->funmi, paymentRecord($held->id), 1_000_000, 'Wrong amount paid; refunded in full');

    $http->assertSent(fn (Request $request): bool => $request->url() === 'https://api.paystack.co/refund' && $request['transaction'] === $reference && $request['amount'] === 1_000_000);
    [$refund] = refundsOf($this->acme);
    expect($refund->invoice_reopened)->toBeFalse()
        ->and(invoiceNumbered($this->acme, 'PR-2026-000002')->status)->toBe(InvoiceStatus::Open)
        ->and(invoiceNumbered($this->acme, FIRST_INVOICE)->status)->toBe(InvoiceStatus::Paid);
});

it('records a bank transfer refunded by GoProperty\'s own transfer, with its reference', function () {
    // December's invoice paid by a bank transfer staff confirmed.
    $this->travelTo(CarbonImmutable::parse('2026-12-01 01:30', 'Africa/Lagos'));
    meterUnits();
    $transfer = inOrganization($this->acme, function (): PlatformPayment {
        $invoice = PlatformInvoice::query()->where('number', 'PR-2026-000002')->sole();
        $payment = new PlatformPayment;
        $payment->forceFill([
            'organization_id' => $invoice->organization_id, 'platform_invoice_id' => $invoice->id, 'method' => PlatformPayment::BANK_TRANSFER,
            'amount_kobo' => $invoice->total_kobo, 'paid_amount_kobo' => $invoice->total_kobo, 'currency' => 'NGN', 'status' => PlatformPaymentStatus::Confirmed,
            'confirmed_at' => now(), 'reviewed_by_staff_id' => $this->kemi->id, 'reviewed_at' => now(),
        ])->save();
        $invoice->forceFill(['status' => InvoiceStatus::Paid, 'paid_at' => now()])->save();

        return $payment;
    });
    $http = refundPaystack();
    consoleAs($this->funmi);

    // The transfer's reference is required.
    Livewire::test(ListPlatformPayments::class)
        ->callTableAction('refund', $transfer->id, ['amount' => '25,800', 'reason' => 'Paid twice'])
        ->assertHasTableActionErrors(['bank_reference']);

    Livewire::test(ListPlatformPayments::class)
        ->callTableAction('refund', $transfer->id, ['amount' => '25,800', 'bank_reference' => 'ZEN-TRF-88812', 'reason' => 'Paid twice'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Recorded: ₦25,800 refunded by bank transfer. The owners were told.');

    $http->assertNothingSent();
    [$refund] = refundsOf($this->acme);
    expect($refund->only(['method', 'status', 'bank_reference', 'paystack_refund_id', 'invoice_reopened']))
        ->toEqual(['method' => 'bank_transfer', 'status' => RefundStatus::Processed, 'bank_reference' => 'ZEN-TRF-88812', 'paystack_refund_id' => null, 'invoice_reopened' => true])
        ->and($refund->processed_at)->not->toBeNull()
        ->and(invoiceNumbered($this->acme, 'PR-2026-000002')->status)->toBe(InvoiceStatus::Open);
    expect((string) (new PlatformRefundIssued($this->acme->id, $refund->id))->toMail($this->owner)->render())->toContain('GoProperty sent it back by bank transfer (reference ZEN-TRF-88812)');
});

it('follows Paystack\'s refund webhooks by asking Paystack, and Check with Paystack does the same', function () {
    $payment = firstPayment($this->acme);
    refundPaystack();
    app(Refunds::class)->request($this->funmi, paymentRecord($payment->id), 500_000, 'Goodwill');
    [$refund] = refundsOf($this->acme);
    expect($refund->status)->toBe(RefundStatus::Processing);

    $refundWebhook = function (string $event, ?string $signature = null) {
        $body = json_encode(['event' => $event, 'data' => ['status' => str_replace('refund.', '', $event), 'transaction_reference' => REFUND_REFERENCE, 'amount' => 500_000, 'currency' => 'NGN']], JSON_THROW_ON_ERROR);

        return test()->call('POST', '/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $body, 'sk_test_goproperty_platform'),
        ], $body);
    };

    // A forged event is dropped; Paystack is not asked.
    $http = refundPaystack(later: 'processed');
    $refundWebhook('refund.processed', 'forged')->assertStatus(401);
    $http->assertNothingSent();
    expect(refundsOf($this->acme)[0]->status)->toBe(RefundStatus::Processing);

    // A signed event: GoProperty asks Paystack about the refund, never trusting the event.
    $refundWebhook('refund.processed')->assertOk();
    $http->assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === 'https://api.paystack.co/refund/9001');
    expect(refundsOf($this->acme)[0]->status)->toBe(RefundStatus::Processed)
        ->and(DB::table('webhook_log')->whereNull('organization_id')->where('event', 'refund.processed')->where('signature_valid', true)->value('outcome'))->toBe('refund')
        ->and(DB::table('webhook_log')->whereNull('organization_id')->where('event', 'refund.processed')->where('signature_valid', true)->value('reference'))->toBe(REFUND_REFERENCE);

    // Another refund that fails at Paystack: Check with Paystack from the payment's page shows it in red.
    refundPaystack(later: 'failed');
    app(Refunds::class)->request($this->funmi, paymentRecord($payment->id), 100_000, 'More goodwill');
    $second = refundsOf($this->acme)[1];
    consoleAs($this->funmi);
    Livewire::test(RefundsRelationManager::class, ['ownerRecord' => paymentRecord($payment->id), 'pageClass' => ViewPlatformPayment::class])
        ->assertTableActionHidden('check', $refund->id)
        ->callTableAction('check', $second->id)
        ->assertNotified('Paystack says: Failed.');
    expect(refundsOf($this->acme)[1]->only(['status', 'failure_reason']))->toEqual(['status' => RefundStatus::Failed, 'failure_reason' => 'Paystack reports the refund as failed.'])
        ->and(AuditEntry::query()->where('event', 'platform_refund.failed')->sole()->causer?->is($this->funmi))->toBeTrue();
});

it('acceptance: the billing pages are policy-checked per staff role', function () {
    $console = '/'.config('app.admin_path');
    $payment = firstPayment($this->acme);
    $invoice = invoiceNumbered($this->acme, FIRST_INVOICE);
    $pages = [
        'invoices' => '/platform-invoices',
        'invoice' => '/platform-invoices/'.$invoice->id,
        'payments' => '/platform-payments',
        'payment' => '/platform-payments/'.$payment->id,
        'refund settings' => '/settings/refunds',
    ];
    $allowed = [
        'super-admin' => ['invoices', 'invoice', 'payments', 'payment', 'refund settings'],
        'finance-admin' => ['invoices', 'invoice', 'payments', 'payment'],
        'support-agent' => ['invoices', 'invoice'],
        'operations-manager' => [],
        'compliance-officer' => [],
        'technical-admin' => [],
        'content-manager' => [],
        'analyst' => [],
    ];

    foreach ($allowed as $role => $opens) {
        consoleAs(staffNamed('Staff'.str_replace('-', '', $role), [$role]));

        foreach ($pages as $name => $path) {
            $response = $this->get($console.$path);
            in_array($name, $opens, true) ? $response->assertOk() : $response->assertForbidden();
        }
    }

    // Staff without 2FA hold nothing.
    consoleAs(staffNamed('NoTwoFactor', ['super-admin'], withTwoFactor: false));
    $this->get($console.'/platform-payments')->assertRedirect();

    // Support sees invoices and may resend, but neither payments nor refunds.
    consoleAs($support = staffNamed('Sola', ['support-agent']));
    Livewire::test(ViewPlatformInvoice::class, ['record' => $invoice->id])->assertActionVisible('resend');
    expect($support->can('refund', paymentRecord($payment->id)))->toBeFalse();

    // Finance may refund; the action is on confirmed and held payments only.
    consoleAs($this->funmi);
    Livewire::test(ListPlatformPayments::class)->assertTableActionVisible('refund', $payment->id)->assertCanSeeTableRecords([paymentRecord($payment->id)]);
    Livewire::test(ViewPlatformPayment::class, ['record' => $payment->id])->assertActionVisible('refund')->assertSee(REFUND_REFERENCE);
});

it('lists invoices across organizations with filters for status, kind and overdue, and shows an invoice\'s lines', function () {
    $other = Organization::factory()->create(['slug' => 'lekki-lets', 'name' => 'Lekki Lets', 'status' => OrganizationStatus::Active]);
    joinAs(User::factory()->create(), $other);
    withUnits($other, 10);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Africa/Lagos'));
    startTrial($other, 'starter', 14);
    $this->travelTo(CarbonImmutable::parse('2026-11-25 11:00', 'Africa/Lagos'));
    endTrials();
    $lekki = inOrganization($other, fn (): PlatformInvoice => PlatformInvoice::query()->sole());
    $acme = invoiceNumbered($this->acme, FIRST_INVOICE);
    $overdue = PlatformInvoiceRecord::query()->find($lekki->id);

    $this->travelTo(CarbonImmutable::parse('2026-12-03 11:00', 'Africa/Lagos'));
    consoleAs($this->funmi);
    $acmeRecord = PlatformInvoiceRecord::query()->findOrFail($acme->id);
    $lekkiRecord = PlatformInvoiceRecord::query()->findOrFail($lekki->id);

    Livewire::test(ListPlatformInvoices::class)
        ->assertCanSeeTableRecords([$acmeRecord, $lekkiRecord])
        ->filterTable('overdue', true)
        ->assertCanSeeTableRecords([$lekkiRecord])->assertCanNotSeeTableRecords([$acmeRecord])
        ->resetTableFilters()
        ->filterTable('status', 'paid')
        ->assertCanSeeTableRecords([$acmeRecord])->assertCanNotSeeTableRecords([$lekkiRecord])
        ->resetTableFilters()
        ->filterTable('kind', 'sms_credits')
        ->assertCanNotSeeTableRecords([$acmeRecord, $lekkiRecord]);
    expect($overdue?->statusLabel())->toBe('Overdue');

    Livewire::test(ViewPlatformInvoice::class, ['record' => $acme->id])
        ->assertSee(FIRST_INVOICE)
        ->assertSee('Adeyemi Realty Ltd')
        ->assertSee('VAT (7.5%): ₦900')
        ->assertSee('Total: ₦12,900');
});

it('sends an invoice email again to the owners, at most 3 times an hour, audited', function () {
    $invoice = invoiceNumbered($this->acme, FIRST_INVOICE);
    $support = staffNamed('Sola', ['support-agent']);
    consoleAs($support);
    Notification::fake();

    Livewire::test(ViewPlatformInvoice::class, ['record' => $invoice->id])
        ->callAction('resend')
        ->assertNotified('Invoice '.FIRST_INVOICE.' sent again to 1 owner.');

    Notification::assertSentToTimes($this->owner, PlatformInvoiceIssued::class, 1);
    Notification::assertNotSentTo($this->admin, PlatformInvoiceIssued::class);
    $entry = AuditEntry::query()->where('event', 'platform_invoice.resent')->sole();
    expect($entry->causer?->is($support))->toBeTrue()->and($entry->properties['recipients'])->toBe(1);

    app(InvoiceEmails::class)->resend($support, $invoice->id);
    app(InvoiceEmails::class)->resend($support, $invoice->id);
    expect(fn () => app(InvoiceEmails::class)->resend($support, $invoice->id))->toThrow(InvoiceEmailRefused::class, '3 times in the last hour')
        ->and(fn () => app(InvoiceEmails::class)->resend(staffNamed('Opeyemi', ['operations-manager']), $invoice->id))->toThrow(InvoiceEmailRefused::class, 'permission')
        ->and(fn () => app(Refunds::class)->resendInvoice(staffNamed('Tunde', ['technical-admin']), PlatformInvoiceRecord::query()->findOrFail($invoice->id)))->toThrow(AuthorizationException::class);
    Notification::assertSentToTimes($this->owner, PlatformInvoiceIssued::class, 3);
});

it('lets only `refunds.configure` set the approval amount, audited', function () {
    $console = '/'.config('app.admin_path');
    consoleAs($this->funmi);
    $this->get($console.'/settings/refunds')->assertForbidden();
    expect(app(PlatformRefunds::class)->approvalAboveKobo())->toBe(10_000_000);

    $root = staffNamed('Root', ['super-admin']);
    consoleAs($root);
    $this->get($console.'/settings/refunds')->assertOk()->assertSee('Refund approval');
    Livewire::test(RefundSettings::class)->fillForm(['above' => 'lots'])->call('mountAction', 'save')->assertHasErrors(['data.above']);
    Livewire::test(RefundSettings::class)->fillForm(['above' => '50,000'])->call('mountAction', 'save')
        ->assertHasNoErrors()->assertNotified('Saved: refunds above ₦50,000 need a second staff member\'s approval');

    expect(app(PlatformRefunds::class)->approvalAboveKobo())->toBe(5_000_000)
        ->and(AuditEntry::query()->where('log_name', 'settings')->where('causer_id', $root->id)->get()->contains(fn (AuditEntry $entry): bool => ($entry->properties['key'] ?? null) === RefundService::APPROVAL_SETTING))->toBeTrue()
        ->and(Money::kobo(app(PlatformRefunds::class)->approvalAboveKobo())->format())->toBe('₦50,000');
});

it('never changes or deletes a refund through the read models', function () {
    refundPaystack();
    app(Refunds::class)->request($this->funmi, paymentRecord(firstPayment($this->acme)->id), 100_000, 'Goodwill');
    $record = PlatformRefundRecord::query()->sole();

    expect(fn () => $record->forceFill(['amount_kobo' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $record->delete())->toThrow(LogicException::class)
        ->and(fn () => inOrganization($this->acme, fn () => PlatformRefund::query()->sole()->delete()))->toThrow(LogicException::class)
        ->and(fn () => inOrganization($this->acme, fn () => PlatformRefund::query()->sole()->forceFill(['amount_kobo' => Money::kobo(1)])->save()))->toThrow(LogicException::class)
        ->and(fn () => paymentRecord(firstPayment($this->acme)->id)->forceFill(['status' => 'failed'])->save())->toThrow(LogicException::class);
    expect(SubscriptionStatus::Active)->toBe(subscriptionOf($this->acme)->status);
});
