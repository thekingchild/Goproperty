<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Enums\BillStatus;
use Modules\Payments\Contracts\PaymentDraft;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentRecorder;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\Payment;
use Modules\Payments\Payments\PaymentRefused;
use Modules\Payments\Payments\PaymentService;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P03-10a: who may see, record, confirm and reject payments
 * (ARCHITECTURE.md § 5, PaymentPolicy): owners, administrators and agents;
 * property owners and tenants confirm nothing; GoProperty staff never
 * record, confirm or reject while impersonating (money actions, AGENT.md
 * § 3 rule 9); another organization's payment guessed by ID answers 404
 * everywhere; an agent assigned to buildings works on their tenants'
 * payments and settles only their buildings' bills (decision 9); a
 * suspended organization's members only read and export, while payments
 * the system records still land.
 */

beforeEach(function () {
    holdInvoicePdfs();
    // Receipts are issued as usual; their PDFs are not these tests' business (P03-10b).
    holdReceiptPdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->otherOwner = joinAs(User::factory()->create(), $this->other);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348030000001', 'email' => 'kemi@example.test']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']), $this->kemi, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    $this->stranger = tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Tenant', 'phone' => '+2348030000009', 'email' => 'foreign@example.test']);
    activeLease($this->other, $this->otherOwner, unitIn($this->other, buildingIn($this->other, ['name' => 'Foreign Court'])), $this->stranger, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    runBillGeneration();

    $this->bill = billsOfTenant($this->acme, $this->kemi)->sole();
    $this->foreignBill = billsOfTenant($this->other, $this->stranger)->sole();
    $this->pending = recordPayment($this->acme, $this->owner, $this->kemi, '100,000', ['reference' => 'ACME-REF-1']);
    $this->foreign = recordPayment($this->other, $this->otherOwner, $this->stranger, '50,000', ['reference' => 'FOREIGN-REF-9']);
});

afterEach(fn () => billsFreshRequest());

/** @return array<string, bool> ability => allowed, as PaymentPolicy answers inside the organization */
function paymentAbilitiesOf(User $user, Organization $organization, Payment $payment): array
{
    return inOrganization($organization, fn (): array => [
        'viewAny' => Gate::forUser($user)->allows('viewAny', Payment::class),
        'view' => Gate::forUser($user)->allows('view', $payment),
        'export' => Gate::forUser($user)->allows('export', Payment::class),
        'create' => Gate::forUser($user)->allows('create', Payment::class),
        'confirm' => Gate::forUser($user)->allows('confirm', $payment),
        'reject' => Gate::forUser($user)->allows('reject', $payment),
        'update' => Gate::forUser($user)->allows('update', $payment),
        'delete' => Gate::forUser($user)->allows('delete', $payment),
    ]);
}

it('enforces the ARCHITECTURE.md § 5 role rules in PaymentPolicy', function (OrganizationRole $role, bool $allowed) {
    $user = joinAs(User::factory()->create(), $this->acme, $role);

    expect(paymentAbilitiesOf($user, $this->acme, $this->pending))->toBe([
        'viewAny' => $allowed, 'view' => $allowed, 'export' => $allowed, 'create' => $allowed,
        'confirm' => $allowed, 'reject' => $allowed, 'update' => false, 'delete' => false,
    ]);
})->with([
    'org-owner' => [OrganizationRole::Owner, true],
    'org-admin' => [OrganizationRole::Admin, true],
    'agent' => [OrganizationRole::Agent, true],
    'property-owner' => [OrganizationRole::PropertyOwner, false],
    'tenant' => [OrganizationRole::Tenant, false],
]);

it('offers confirm and reject only while a payment waits', function () {
    $confirmed = confirmPayment($this->acme, $this->owner, storedPayment($this->acme, $this->pending));

    expect(paymentAbilitiesOf($this->owner, $this->acme, storedPayment($this->acme, $confirmed)))->toMatchArray(['view' => true, 'confirm' => false, 'reject' => false, 'delete' => false]);
});

it('opens the payment pages for owners, administrators and agents, and 404 for property owners, tenants and strangers', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);

    foreach ([$this->owner, $admin, $agent] as $user) {
        paymentsPage($user)->assertOk()->assertSee('Kemi Adebayo')->assertSee('data-test="export-payments-csv"', false)->assertSee('data-nav="payments"', false)->assertSee('data-test="record-payment"', false);
        paymentsPage($user, "/{$this->pending->id}")->assertOk()->assertSee('data-test="confirm-payment"', false)->assertSee('data-test="reject-payment"', false);
        paymentsPage($user, '/create')->assertOk()->assertSee('Kemi Adebayo');
    }

    foreach ([joinAs(User::factory()->create(), $this->acme, OrganizationRole::PropertyOwner), joinAs(User::factory()->create(), $this->acme, OrganizationRole::Tenant), User::factory()->create()] as $user) {
        paymentsPage($user)->assertNotFound();
        paymentsPage($user, "/{$this->pending->id}")->assertNotFound();
        paymentsPage($user, '/create')->assertNotFound();
    }
});

it('sub: tenants cannot confirm or reject a payment, not even their own', function () {
    $tenantUser = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Tenant);
    inOrganization($this->acme, fn () => $this->kemi->forceFill(['user_id' => $tenantUser->id])->save());

    expect(fn () => confirmPayment($this->acme, $tenantUser, $this->pending))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => app(PaymentService::class)->reject($tenantUser, $this->pending, 'Not mine')))->toThrow(AuthorizationException::class)
        ->and(fn () => recordPayment($this->acme, $tenantUser, $this->kemi, '100,000'))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => app(PaymentRecorder::class)->recordConfirmed(new PaymentDraft(
            tenantProfileId: $this->kemi->id,
            amount: Money::naira(100_000),
            method: PaymentMethod::BankTransfer,
            source: PaymentSource::StatementMatch,
            idempotencyKey: 'statement-line:1',
            paidAt: CarbonImmutable::now(),
            by: $tenantUser,
        ))))->toThrow(AuthorizationException::class)
        ->and(storedPayment($this->acme, $this->pending)->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and(paymentsOf($this->acme))->toHaveCount(1);
});

it('sub: impersonating staff look at and export payments, but never record, confirm or reject one, in either mode', function (ImpersonationMode $mode) {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    $handOff = $mode === ImpersonationMode::Write
        ? startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write)
        : startSupport($staff, $this->owner, $this->acme);
    enterSupport($handOff)->assertRedirect('/o/acme-homes');

    $list = asSupport()->get('/o/acme-homes/payments')->assertOk()->assertSee('Kemi Adebayo')->assertSee('data-test="export-payments-csv"', false)->assertDontSee('data-test="record-payment"', false);
    $show = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/payments/{$this->pending->id}")->assertOk()
        ->assertDontSee('data-test="confirm-payment"', false)->assertDontSee('data-test="reject-payment"', false));
    asSupport()->get('/o/acme-homes/payments/create')->assertForbidden();

    freshRequest();
    LivewireHttp::update(test(), $show, ['note' => 'Seen'], [['confirm']])->assertForbidden();
    freshRequest();
    LivewireHttp::update(test(), $show, ['reason' => 'Duplicate'], [['reject']])->assertForbidden();
    freshRequest();
    $pressed = LivewireHttp::update(test(), LivewireHttp::snapshot($list), [], [['export', ['payments', 'csv']]])->assertOk();

    expect(LivewireHttp::redirect($pressed))->toContain('/exports/')
        ->and(storedPayment($this->acme, $this->pending)->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and(storedBill($this->acme, $this->bill)->paid_kobo->kobo)->toBe(0)
        ->and(AuditEntry::query()->whereIn('event', ['payment.confirmed', 'payment.rejected'])->exists())->toBeFalse();

    if ($mode === ImpersonationMode::Write) {
        expect(AuditEntry::query()->where('event', 'impersonation.blocked')->where('subject_id', $handOff->session->id)->exists())->toBeTrue();
    }
})->with([
    'read-only' => [ImpersonationMode::ReadOnly],
    'write' => [ImpersonationMode::Write],
]);

it('answers 404 for another organization\'s payment guessed by ID, on pages, lists and actions', function () {
    paymentsPage($this->owner, "/{$this->foreign->id}")->assertNotFound();
    paymentsPage($this->owner, '/999999')->assertNotFound();
    paymentsPage($this->owner, '/abc')->assertNotFound();

    foreach (['', '?q=Foreign', '?q=FOREIGN-REF-9', '?tenant='.$this->stranger->id, '?bill='.$this->foreignBill->id, '?status=pending_confirmation'] as $query) {
        paymentsPage($this->owner, $query)->assertOk()->assertDontSee('Foreign Tenant')->assertDontSee('data-payment="'.$this->foreign->id.'"', false);
    }

    // The record form offers neither the other organization's tenant nor its bill.
    paymentsPage($this->owner, '/create?tenant='.$this->stranger->id)->assertOk()->assertDontSee('Foreign Tenant');
    paymentsPage($this->owner, '/create?bill='.$this->foreignBill->id)->assertOk()->assertDontSee($this->foreignBill->number);

    // A tampered snapshot (production: debug off answers 419).
    config(['app.debug' => false]);
    $data = json_decode(paymentsSnapshot($this->owner, "/{$this->pending->id}"), true);
    $data['data']['paymentId'] = $this->foreign->id;
    paymentsUpdate($this->owner, (string) json_encode($data), [], [['confirm']])->assertStatus(419);

    // The writer itself refuses another organization's payment, tenant and bill.
    expect(fn () => confirmPayment($this->acme, $this->owner, $this->foreign))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => app(PaymentService::class)->reject($this->owner, $this->foreign, 'Hijack')))->toThrow(AuthorizationException::class)
        ->and(fn () => recordPayment($this->acme, $this->owner, $this->stranger, '100,000'))->toThrow(ValidationException::class, 'Choose a tenant.')
        ->and(fn () => recordPayment($this->acme, $this->owner, $this->kemi, '100,000', ['billId' => (string) $this->foreignBill->id]))->toThrow(ValidationException::class, 'Choose one of the tenant\'s bills.')
        ->and(fn () => confirmPayment($this->acme, $this->owner, $this->pending, [$this->foreignBill->id => '10,000']))->toThrow(PaymentRefused::class)
        ->and(fn () => inOrganization($this->acme, fn () => app(PaymentRecorder::class)->record(new PaymentDraft(
            tenantProfileId: $this->stranger->id,
            amount: Money::naira(1_000),
            method: PaymentMethod::BankTransfer,
            source: PaymentSource::StatementMatch,
            idempotencyKey: 'statement-line:9',
            paidAt: CarbonImmutable::now(),
        ))))->toThrow(ValidationException::class)
        ->and(storedPayment($this->other, $this->foreign)->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and(storedBill($this->other, $this->foreignBill)->paid_kobo->kobo)->toBe(0)
        ->and(paymentsOf($this->acme))->toHaveCount(1);

    // A member of both organizations sees each one's own payments there.
    joinAs($this->owner, $this->other);
    expect(listedPayments(paymentsPage($this->owner)))->toBe([$this->pending->id])
        ->and(listedPayments(paymentsPage($this->owner, '', 'other-realty')))->toBe([$this->foreign->id]);
});

it('shows an agent assigned to buildings only their tenants\' payments, and settles only their buildings\' bills', function () {
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
    $oakTenant = tenantIn($this->acme, ['first_name' => 'Oak', 'last_name' => 'Tenant', 'phone' => '+2348030000003', 'email' => 'oak@example.test']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $oak, ['unit_label' => 'Oak 1']), $oakTenant, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    // Kemi also rents a shop in Oak Lodge.
    activeLease($this->acme, $this->owner, unitIn($this->acme, $oak, ['unit_label' => 'Shop 1']), $this->kemi, ['billingCycle' => 'monthly', 'rent' => '40,000', 'deposit' => '0']);
    runBillGeneration();
    $oakPayment = recordPayment($this->acme, $this->owner, $oakTenant, '100,000');
    $kemisShop = billsOfTenant($this->acme, $this->kemi)->firstWhere('property_id', $oak->id);
    assignIn($this->acme, $agent, $this->palm);

    expect(listedPayments(paymentsPage($agent)))->toBe([$this->pending->id])
        ->and(listedPayments(paymentsPage($agent, '?tenant='.$oakTenant->id)))->toBe([]);
    paymentsPage($agent)->assertDontSee('Oak Tenant');
    paymentsPage($agent, "/{$oakPayment->id}")->assertNotFound();
    paymentsPage($agent, '/create')->assertOk()->assertDontSee('Oak Tenant');

    expect(fn () => confirmPayment($this->acme, $agent, $oakPayment))->toThrow(fn (AuthorizationException $e) => expect($e->status())->toBe(404))
        ->and(fn () => recordPayment($this->acme, $agent, $oakTenant, '100,000'))->toThrow(ValidationException::class, 'Choose a tenant.')
        ->and(fn () => recordPayment($this->acme, $agent, $this->kemi, '40,000', ['billId' => (string) $kemisShop->id]))->toThrow(ValidationException::class, 'Choose one of the tenant\'s bills.');

    // The export holds the same rows as the page.
    expect(exportColumn(exportRows($agent, '/payments', 'payments', 'csv'), 'Reference'))->toBe(['ACME-REF-1']);

    // Kemi's ₦150,000 confirmed by the agent: her flat's bill only; Oak Lodge's shop bill is not the agent's to settle.
    $payment = confirmedPayment($this->acme, $agent, $this->kemi, '150,000');
    expect(allocationsOf($this->acme, $payment))->toBe([$this->bill->id => 10_000_000])
        ->and(storedBill($this->acme, $kemisShop)->paid_kobo->kobo)->toBe(0)
        ->and(creditOf($this->acme, $this->kemi)->kobo)->toBe(5_000_000);

    // An agent with no assignment sees every payment.
    expect(listedPayments(paymentsPage(joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent))))->toHaveCount(3);
});

it('keeps payments read-only for members of a suspended organization, while payments the system records still land', function () {
    $page = paymentsSnapshot($this->owner, "/{$this->pending->id}");
    $form = paymentsSnapshot($this->owner, '/create');
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    paymentsPage($this->owner)->assertOk()->assertSee('Kemi Adebayo')->assertSee('data-test="export-payments-csv"', false)->assertDontSee('data-test="record-payment"', false);
    paymentsPage($this->owner, "/{$this->pending->id}")->assertOk()->assertDontSee('data-test="confirm-payment"', false)->assertDontSee('data-test="reject-payment"', false);
    paymentsUpdate($this->owner, $page, [], [['confirm']])->assertForbidden();
    paymentsUpdate($this->owner, $page, ['reason' => 'Duplicate'], [['reject']])->assertForbidden();
    paymentsUpdate($this->owner, $form, ['tenantId' => (string) $this->kemi->id, 'amount' => '5,000'], [['save']])->assertForbidden();
    expect(fn () => confirmPayment($this->acme, $this->owner, storedPayment($this->acme, $this->pending)))->toThrow(AuthorizationException::class);

    $pressed = paymentsUpdate($this->owner, paymentsSnapshot($this->owner), [], [['export', ['payments', 'csv']]])->assertOk();
    expect(LivewireHttp::redirect($pressed))->toContain('/exports/')
        ->and(storedPayment($this->acme, $this->pending)->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and(paymentsOf($this->acme))->toHaveCount(1);

    // The money already arrived in the organization's own account (a verified Paystack payment): recorded and confirmed.
    $recorded = inOrganization($this->acme, fn () => app(PaymentRecorder::class)->recordConfirmed(new PaymentDraft(
        tenantProfileId: $this->kemi->id,
        amount: Money::naira(120_000),
        method: PaymentMethod::Paystack,
        source: PaymentSource::PaystackWebhook,
        idempotencyKey: 'paystack:T-555',
        paidAt: CarbonImmutable::now(),
        billId: $this->bill->id,
        paystackReference: 'T-555',
    )));
    expect($recorded->isConfirmed())->toBeTrue()
        ->and(storedBill($this->acme, $this->bill)->status)->toBe(BillStatus::Paid)
        ->and(creditOf($this->acme, $this->kemi)->kobo)->toBe(2_000_000);

    // Bills keep being issued while suspended (P03-04b), and take the credit.
    $this->travelTo('2027-01-25 09:00:00');
    runBillGeneration();
    expect(billsOfTenant($this->acme, $this->kemi)->last()->paid_kobo->kobo)->toBe(2_000_000);
});
