<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Settings\SettingsStore;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\Bill;
use Modules\Leasing\Contracts\RentPaymentLimits;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;
use Modules\Payments\Contracts\PartPayments;
use Modules\Payments\Contracts\PaymentDraft;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentRecorder;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\Payment;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/proof-helpers.php';
require_once __DIR__.'/../../../Leasing/tests/Feature/helpers.php';

/*
 * P03-17: the agency sets how many part payments a tenant may make on a
 * rent bill (docs/plans/rent-part-payments-2026-10-31.md). Acceptance: "A
 * tenant cannot send a proof that exceeds the bill's limit or leaves the
 * last allowed payment short; a member-recorded or bank-matched payment
 * over the limit is confirmed and flagged. The lease's value wins over the
 * organization default; only owners and administrators change either,
 * audited."
 *
 * The world: Kemi rents Flat 1 at Palm Court for ₦100,000 a month; her
 * December bill is issued. She uses the tenant portal.
 */

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('media');
    holdInvoicePdfs();
    holdReceiptPdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Tunde Bakare']), $this->acme);
    $this->admin = joinAs(User::factory()->create(['name' => 'Bisi Admin']), $this->acme, OrganizationRole::Admin);
    $this->agent = joinAs(User::factory()->create(['name' => 'Ade Agent']), $this->acme, OrganizationRole::Agent);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348030000001', 'email' => 'kemi@example.test']);
    $this->lease = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']), $this->kemi, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    runBillGeneration();
    $this->bill = billsOfTenant($this->acme, $this->kemi)->sole();
    $this->kemiUser = proofPortalAccount($this->acme, $this->kemi, User::factory()->create(['name' => 'Kemi Adebayo', 'email' => 'kemi@example.test']));
});

afterEach(fn () => billsFreshRequest());

function setLeaseRentPayments(User $by, Lease $lease, ?int $limit): Lease
{
    return inOrganization($lease->organization()->firstOrFail(), fn (): Lease => app(LeaseService::class)->setRentPaymentLimit($by, Lease::query()->findOrFail($lease->id), $limit));
}

function setDefaultRentPayments(Organization $organization, ?int $limit): void
{
    inOrganization($organization, fn () => app(SettingsStore::class)->set(RentPaymentLimits::SETTING, $limit, $organization));
}

/** The tenant's proof, or the validation message it was refused with. */
function tenantProofOrRefusal(Organization $organization, User $tenant, Bill $bill, string $amount, int $seed): string
{
    try {
        submitTenantProof($organization, $tenant, $bill, proofFile('transfer.png', $seed), ['amount' => $amount]);
    } catch (ValidationException $refused) {
        return (string) collect($refused->errors())->flatten()->first();
    }

    return 'sent';
}

/** A tenant portal page as the user, in a fresh request. */
function rentPortalPage(User $user, string $path = ''): TestResponse
{
    billsFreshRequest();

    return test()->actingAs($user)->get('/portal'.$path);
}

function latestPayment(Organization $organization): Payment
{
    return inOrganization($organization, fn (): Payment => Payment::query()->orderByDesc('id')->firstOrFail());
}

it('acceptance: a tenant cannot send a proof that exceeds the limit or leaves the last allowed payment short', function () {
    setLeaseRentPayments($this->owner, $this->lease, 3);

    // First part: ₦30,000, confirmed by the agency.
    expect(tenantProofOrRefusal($this->acme, $this->kemiUser, $this->bill, '30,000', 1))->toBe('sent');
    confirmPayment($this->acme, $this->owner, latestPayment($this->acme));

    // Second part, still waiting for the agency: it counts while it waits.
    expect(tenantProofOrRefusal($this->acme, $this->kemiUser, $this->bill, '30,000', 2))->toBe('sent');
    $allowance = inOrganization($this->acme, fn () => app(PartPayments::class)->forBill($this->bill->id));
    expect([$allowance->limit, $allowance->made, $allowance->waiting, $allowance->remaining()])->toBe([3, 1, 1, 1]);

    // The third is the last allowed: it must clear what is owed beyond the waiting one (₦100,000 − 30,000 − 30,000).
    expect(tenantProofOrRefusal($this->acme, $this->kemiUser, $this->bill, '30,000', 3))
        ->toBe('This bill can be paid in at most 3 payments, so this one must be the full ₦40,000 still owed.');
    rentPortalPage($this->kemiUser, '/bills/'.$this->bill->id.'/proof')->assertOk()
        ->assertSee('You can pay this bill in up to 3 payments (1 made). This payment must be the full ₦40,000.');

    expect(tenantProofOrRefusal($this->acme, $this->kemiUser, $this->bill, '40,000', 4))->toBe('sent');

    // A fourth is refused, even before the agency confirms the others.
    expect(tenantProofOrRefusal($this->acme, $this->kemiUser, $this->bill, '10,000', 5))
        ->toBe('This bill can be paid in at most 3 payments, and all 3 have been made or are waiting for the agency to confirm them. Contact the agency if you paid again.');

    $payments = inOrganization($this->acme, fn () => Payment::query()->where('bill_id', $this->bill->id)->orderBy('id')->get());
    expect($payments->map(fn (Payment $payment): array => [$payment->amount_kobo->kobo, $payment->status])->all())->toBe([
        [3_000_000, PaymentStatus::Confirmed], [3_000_000, PaymentStatus::PendingConfirmation], [4_000_000, PaymentStatus::PendingConfirmation],
    ]);

    // Confirmed, the three settle the bill, with no flag.
    confirmPayment($this->acme, $this->owner, $payments[1]);
    confirmPayment($this->acme, $this->owner, $payments[2]);
    expect(inOrganization($this->acme, fn () => Bill::query()->findOrFail($this->bill->id))->status->value)->toBe('paid')
        ->and(AuditEntry::query()->where('event', 'payment.confirmed')->get()->filter(fn (AuditEntry $entry): bool => isset($entry->properties['over_part_payment_limit'])))->toHaveCount(0);
});

it('acceptance: a payment the agency records or matches over the limit is confirmed and flagged', function () {
    setDefaultRentPayments($this->acme, 1);

    // One payment: the tenant's half payment is refused…
    expect(tenantProofOrRefusal($this->acme, $this->kemiUser, $this->bill, '50,000', 1))
        ->toBe('This bill is paid in one payment, so it must be the full ₦100,000.');
    rentPortalPage($this->kemiUser, '/bills/'.$this->bill->id)->assertOk()->assertSee('This bill is paid in one payment.');

    // …but money that reached the agency is never refused. A member records ₦50,000 cash for the bill: one payment, within the limit.
    $first = confirmedPayment($this->acme, $this->owner, $this->kemi, '50,000', ['billId' => (string) $this->bill->id, 'method' => 'cash', 'note' => 'Paid at the office']);
    expect(AuditEntry::query()->where('event', 'payment.confirmed')->where('subject_id', $first->id)->sole()->properties->has('over_part_payment_limit'))->toBeFalse();

    // The rest arrives by bank and is matched from the statement: a second payment on a one-payment bill.
    $waiting = inOrganization($this->acme, fn () => app(PaymentRecorder::class)->record(new PaymentDraft(
        tenantProfileId: $this->kemi->id, amount: Money::naira(50_000), method: PaymentMethod::BankTransfer, source: PaymentSource::StatementMatch,
        idempotencyKey: 'statement-line:77', paidAt: CarbonImmutable::parse('2026-12-24 15:00'), payerName: 'KEMI ADEBAYO', billId: $this->bill->id,
    )));

    // Waiting: the queue and the payment page flag it before anyone confirms it.
    paymentsPage($this->owner, '?status=pending_confirmation')->assertOk()->assertSee('data-test="over-part-payments-'.$waiting->paymentId.'"', false);
    paymentsPage($this->owner, '/'.$waiting->paymentId)->assertOk()
        ->assertSee('Over the agreed number of part payments: confirming makes this payment 2 of 1 on bill '.$this->bill->number.'.');

    confirmPayment($this->acme, $this->owner, inOrganization($this->acme, fn () => Payment::query()->findOrFail($waiting->paymentId)));

    $audit = AuditEntry::query()->where('event', 'payment.confirmed')->where('subject_id', $waiting->paymentId)->sole();
    expect($audit->properties['over_part_payment_limit'])->toEqual([['bill_id' => $this->bill->id, 'number' => $this->bill->number, 'payments' => 2, 'limit' => 1]])
        ->and(inOrganization($this->acme, fn () => Bill::query()->findOrFail($this->bill->id))->status->value)->toBe('paid');
    paymentsPage($this->owner, '/'.$waiting->paymentId)->assertOk()
        ->assertSee('Over the agreed number of part payments: bill '.$this->bill->number.' has 2 payments; its lease allows 1.');
    $this->actingAs($this->owner)->get('/o/acme-homes/bills/'.$this->bill->id)->assertOk()->assertSee('2 of 1 allowed');

    // Paystack online always asks for the full balance, and a bank transfer "works for any amount": the agency's own path again, already confirmed.
    $matched = inOrganization($this->acme, fn () => app(PaymentRecorder::class)->recordConfirmed(new PaymentDraft(
        tenantProfileId: $this->kemi->id, amount: Money::naira(1_000), method: PaymentMethod::BankTransfer, source: PaymentSource::StatementMatch,
        idempotencyKey: 'statement-line:78', paidAt: CarbonImmutable::parse('2026-12-24 16:00'), billId: $this->bill->id,
    )));
    expect(inOrganization($this->acme, fn () => Payment::query()->findOrFail($matched->paymentId))->status)->toBe(PaymentStatus::Confirmed);
});

it('acceptance: the lease\'s value wins over the organization\'s default, empty everywhere means no limit, and only owners and administrators change them, audited', function () {
    $limits = fn (): array => inOrganization($this->acme, fn (): array => [
        app(RentPaymentLimits::class)->organizationDefault(),
        app(RentPaymentLimits::class)->forLease($this->lease->id),
        app(PartPayments::class)->forBill($this->bill->id)?->limit,
    ]);

    // Nothing set: no limit, and nothing is shown.
    expect($limits())->toBe([null, null, null]);
    rentPortalPage($this->kemiUser, '/bills/'.$this->bill->id)->assertOk()->assertDontSee('data-test="portal-bill-part-payments"', false);
    expect(tenantProofOrRefusal($this->acme, $this->kemiUser, $this->bill, '1,000', 1))->toBe('sent');

    // The organization's default, set by an administrator on Settings → Rent payments.
    $page = LivewireHttp::snapshot($this->actingAs($this->admin)->get('/o/acme-homes/settings/payments/rent')->assertOk()->assertSee('Part payments of rent'));
    billsFreshRequest();
    LivewireHttp::update($this->actingAs($this->admin), $page, ['limit' => '4'], [['save', []]])->assertOk()->assertSee('Saved. Tenants may pay a rent bill in up to 4 payments');
    expect($limits())->toBe([4, 4, 4]);

    // The lease's own value wins, set by the owner on the lease's page.
    billsFreshRequest();
    $snapshot = leasesSnapshot($this->owner, '/'.$this->lease->id);
    leasesUpdate($this->owner, $snapshot, ['rentPayments' => '2'], [['setRentPayments', []]])->assertOk()->assertSee('Saved: rent bills of this lease follow the new number of payments');
    expect($limits())->toBe([4, 2, 2]);
    leasesPage($this->owner, '/'.$this->lease->id)->assertOk()->assertSee('2 payments')->assertSee('(this lease)');
    rentPortalPage($this->kemiUser, '/bills/'.$this->bill->id)->assertOk()->assertSee('You can pay this bill in up to 2 payments (0 made).');
    billsFreshRequest();
    $this->get('/pay/'.$this->bill->payment_reference)->assertOk()->assertSee('You can pay this bill in up to 2 payments (0 made).');

    // Back to the default.
    setLeaseRentPayments($this->owner, $this->lease, null);
    expect($limits())->toBe([4, 4, 4]);

    // Audited: who changed what.
    expect(AuditEntry::query()->where('event', 'lease.rent_payments_changed')->orderBy('id')->get()->map(fn (AuditEntry $entry): array => [$entry->causer_id, $entry->properties['before'], $entry->properties['after']])->all())
        ->toBe([[$this->owner->id, null, 2], [$this->owner->id, 2, null]])
        ->and(AuditEntry::query()->where('log_name', 'settings')->where('causer_id', $this->admin->id)->sole()->properties['key'])->toBe(RentPaymentLimits::SETTING);

    // Agents see both but change neither.
    $abilities = inOrganization($this->acme, fn (): array => [
        Gate::forUser($this->agent)->allows('setPaymentLimit', Lease::query()->findOrFail($this->lease->id)),
        Gate::forUser($this->agent)->allows('updateProfile', $this->acme),
        Gate::forUser($this->admin)->allows('setPaymentLimit', Lease::query()->findOrFail($this->lease->id)),
    ]);
    expect($abilities)->toBe([false, false, true]);
    expect(fn () => setLeaseRentPayments($this->agent, $this->lease, 1))->toThrow(AuthorizationException::class);
    billsFreshRequest();
    leasesPage($this->agent, '/'.$this->lease->id)->assertOk()->assertSee('4 payments')->assertDontSee('data-test="rent-payments-form"', false);
    // Settings pages are not an agent's (no settings.view).
    billsFreshRequest();
    $this->actingAs($this->agent)->get('/o/acme-homes/settings/payments/rent')->assertForbidden();
    expect($limits())->toBe([4, 4, 4]);

    // Out of range, and an ended lease, are refused.
    expect(fn () => setLeaseRentPayments($this->owner, $this->lease, 13))->toThrow(ValidationException::class);
    inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, Lease::query()->findOrFail($this->lease->id), 'Moved out early'));
    expect(fn () => setLeaseRentPayments($this->owner, $this->lease, 1))->toThrow(AuthorizationException::class);
});

it('lets the agency upload a proof over the limit for the tenant, and limits rent bills only', function () {
    setDefaultRentPayments($this->acme, 1);

    // A member uploads what the tenant sent them: the agency's own path, not refused.
    submitMemberProof($this->acme, $this->owner, $this->kemi, $this->bill, proofFile('from-whatsapp.png', 9), ['amount' => '20,000']);
    expect(inOrganization($this->acme, fn () => app(PartPayments::class)->forBill($this->bill->id))?->waiting)->toBe(1);

    // The tenant's own proof is then refused: the one payment is waiting.
    expect(tenantProofOrRefusal($this->acme, $this->kemiUser, $this->bill, '100,000', 10))
        ->toBe('This bill is paid in one payment, which has been made or is waiting for the agency to confirm it. Contact the agency if you paid again.');

    // A bill that is not rent has no limit.
    inOrganization($this->acme, fn () => DB::table('bills')->where('id', $this->bill->id)->update(['type' => 'other']));
    expect(inOrganization($this->acme, fn () => app(PartPayments::class)->forBill($this->bill->id)))->toBeNull()
        ->and(tenantProofOrRefusal($this->acme, $this->kemiUser, $this->bill, '5,000', 11))->toBe('sent');
});
