<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Billing\Bills\BillRefused;
use Modules\Billing\Bills\BillService;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Contracts\BillNotPayable;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Events\BillVoided;
use Modules\Billing\Models\Bill;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P03-04b: who may see and void bills (ARCHITECTURE.md § 5, BillPolicy):
 * owners, administrators and agents; another organization's bill guessed
 * by ID answers 404 everywhere; an agent assigned to buildings sees only
 * those buildings' bills (decision 9, BuildingAccess); a suspended
 * organization is read-only; voiding is audited with its reason, refused
 * for a bill with payments, and never done while staff impersonate.
 */

beforeEach(function () {
    // Bills issue their invoice PDF on the pdf queue (P03-05c); not this test's business.
    holdInvoicePdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->otherOwner = joinAs(User::factory()->create(), $this->other);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348030000001', 'email' => 'kemi@example.test']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']), $this->kemi);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 2']), tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348030000002', 'email' => 'tunde@example.test']));
    activeLease($this->other, $this->otherOwner, unitIn($this->other, buildingIn($this->other, ['name' => 'Foreign Court'])), tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Tenant', 'phone' => '+2348030000009', 'email' => 'foreign@example.test']));
    runBillGeneration();

    [$this->bill, $this->paidBill] = billsOf($this->acme)->all();
    inOrganization($this->acme, fn () => DB::transaction(fn () => app(BillLedger::class)->apply($this->paidBill->id, Money::naira(100_000))));
    $this->foreign = billsOf($this->other)->sole();
});

afterEach(fn () => billsFreshRequest());

/** @return array<string, bool> ability => allowed, as BillPolicy answers inside the organization */
function billAbilitiesOf(User $user, Organization $organization, Bill $bill, Bill $paidBill): array
{
    return inOrganization($organization, fn (): array => [
        'viewAny' => Gate::forUser($user)->allows('viewAny', Bill::class),
        'view' => Gate::forUser($user)->allows('view', $bill),
        'export' => Gate::forUser($user)->allows('export', Bill::class),
        'void' => Gate::forUser($user)->allows('void', storedBill($organization, $bill)),
        'voidPaid' => Gate::forUser($user)->allows('void', storedBill($organization, $paidBill)),
    ]);
}

it('enforces the ARCHITECTURE.md § 5 role rules in BillPolicy', function (OrganizationRole $role, bool $allowed) {
    $user = joinAs(User::factory()->create(), $this->acme, $role);

    expect(billAbilitiesOf($user, $this->acme, $this->bill, $this->paidBill))
        ->toBe(['viewAny' => $allowed, 'view' => $allowed, 'export' => $allowed, 'void' => $allowed, 'voidPaid' => false]);
})->with([
    'org-owner' => [OrganizationRole::Owner, true],
    'org-admin' => [OrganizationRole::Admin, true],
    'agent' => [OrganizationRole::Agent, true],
    'property-owner' => [OrganizationRole::PropertyOwner, false],
    'tenant' => [OrganizationRole::Tenant, false],
]);

it('opens the bill pages for owners, administrators and agents, and 404 for property owners, tenants and strangers', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);

    foreach ([$this->owner, $admin, $agent] as $user) {
        billsPage($user)->assertOk()->assertSee('Kemi Adebayo')->assertSee('data-test="export-bills-csv"', false)->assertSee('data-nav="bills"', false);
        billsPage($user, "/{$this->bill->id}")->assertOk()->assertSee($this->bill->payment_reference)->assertSee('data-test="void-bill"', false);
        billsPage($user, "/{$this->paidBill->id}")->assertOk()->assertDontSee('data-test="void-bill"', false);
    }

    foreach ([joinAs(User::factory()->create(), $this->acme, OrganizationRole::PropertyOwner), joinAs(User::factory()->create(), $this->acme, OrganizationRole::Tenant), User::factory()->create()] as $user) {
        billsPage($user)->assertNotFound();
        billsPage($user, "/{$this->bill->id}")->assertNotFound();
    }
});

it('voids a bill with a reason, audited, and the void bill accepts nothing afterwards', function () {
    $voided = [];
    Event::listen(BillVoided::class, function (BillVoided $event) use (&$voided): void {
        $voided[] = $event->billId;
    });
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $page = billsSnapshot($agent, "/{$this->bill->id}");

    // A reason is required.
    $response = billsUpdate($agent, $page, ['reason' => ' '], [['void']])->assertOk();
    expect(LivewireHttp::errors($response))->toHaveKey('reason')
        ->and(storedBill($this->acme, $this->bill)->status)->toBe(BillStatus::Unpaid);

    billsUpdate($agent, $page, ['reason' => 'Entered for the wrong   tenant'], [['void']])->assertOk()->assertSee('The bill is void.');

    $bill = storedBill($this->acme, $this->bill);
    $audit = AuditEntry::query()->where('event', 'bill.voided')->sole();
    expect($bill->status)->toBe(BillStatus::Void)
        ->and($bill->void_reason)->toBe('Entered for the wrong tenant')
        ->and($bill->voided_by_user_id)->toBe($agent->id)
        ->and($bill->voided_at?->toDateTimeString())->toBe('2026-12-25 09:00:00')
        ->and($bill->outstanding()->kobo)->toBe(0)
        ->and($audit->log_name)->toBe('bills')
        ->and($audit->subject_id)->toBe($bill->id)
        ->and($audit->causer_id)->toBe($agent->id)
        ->and($audit->properties['reason'])->toBe('Entered for the wrong tenant')
        ->and($audit->properties['previous_status'])->toBe('unpaid')
        ->and($audit->properties['amount_kobo'])->toBe(150_000_000)
        ->and($voided)->toBe([$bill->id]);

    billsPage($agent, "/{$bill->id}")->assertOk()->assertSee('Entered for the wrong tenant')->assertDontSee('data-test="void-bill"', false);

    // Voiding again is refused; a payment is refused.
    expect(fn () => inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, $bill, 'Again')))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => DB::transaction(fn () => app(BillLedger::class)->apply($bill->id, Money::naira(1)))))->toThrow(BillNotPayable::class)
        ->and(AuditEntry::query()->where('event', 'bill.voided')->count())->toBe(1);
});

it('refuses to void a bill with payments applied, and changes nothing', function () {
    // Past the policy (as if the payment landed after the page opened): the service checks under the lock.
    Gate::before(fn () => true);

    expect(fn () => inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, $this->paidBill, 'Mistake')))
        ->toThrow(BillRefused::class);

    $page = billsSnapshot($this->owner, "/{$this->bill->id}");
    inOrganization($this->acme, fn () => DB::transaction(fn () => app(BillLedger::class)->apply($this->bill->id, Money::naira(1))));
    $response = billsUpdate($this->owner, $page, ['reason' => 'Mistake'], [['void']])->assertOk();

    expect(LivewireHttp::html($response))->toContain('data-test="bill-warning"')->toContain('Payments of ₦1 are applied to this bill. Reverse them before voiding it.')
        ->and(storedBill($this->acme, $this->paidBill)->status)->toBe(BillStatus::PartiallyPaid)
        ->and(storedBill($this->acme, $this->bill)->status)->toBe(BillStatus::PartiallyPaid)
        ->and(AuditEntry::query()->where('event', 'bill.voided')->exists())->toBeFalse();
});

it('answers 404 for another organization\'s bill guessed by ID, on pages, lists and actions', function () {
    billsPage($this->owner, "/{$this->foreign->id}")->assertNotFound();
    billsPage($this->owner, '/999999')->assertNotFound();
    billsPage($this->owner, '/abc')->assertNotFound();

    foreach (['', '?q=Foreign', '?q='.urlencode($this->foreign->number), '?q='.urlencode($this->foreign->payment_reference), '?building='.$this->foreign->property_id, '?lease='.$this->foreign->lease_id, '?tenant='.$this->foreign->tenant_profile_id] as $query) {
        billsPage($this->owner, $query)->assertOk()->assertDontSee('Foreign Tenant')->assertDontSee('data-bill="'.$this->foreign->id.'"', false);
    }

    // A tampered snapshot (production: debug off answers 419).
    config(['app.debug' => false]);
    $data = json_decode(billsSnapshot($this->owner, "/{$this->bill->id}"), true);
    $data['data']['billId'] = $this->foreign->id;
    billsUpdate($this->owner, (string) json_encode($data), ['reason' => 'Hijack'], [['void']])->assertStatus(419);

    // The writer itself refuses a foreign bill handed to it.
    expect(fn () => inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, $this->foreign, 'Hijack')))->toThrow(AuthorizationException::class)
        ->and(storedBill($this->other, $this->foreign)->status)->toBe(BillStatus::Unpaid);

    // A member of both organizations sees each one's own bills there.
    joinAs($this->owner, $this->other);
    expect(listedBills(billsPage($this->owner)))->toEqualCanonicalizing([$this->bill->id, $this->paidBill->id])
        ->and(listedBills(billsPage($this->owner, '', 'other-realty')))->toBe([$this->foreign->id]);
});

it('shows an agent assigned to buildings only those buildings\' bills, and 404 for the others', function () {
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $oak), tenantIn($this->acme, ['first_name' => 'Oak', 'last_name' => 'Tenant', 'phone' => '+2348030000003', 'email' => 'oak@example.test']));
    runBillGeneration();
    $oakBill = billsOf($this->acme)->firstWhere('property_id', $oak->id);
    assignIn($this->acme, $agent, $this->palm);

    expect(listedBills(billsPage($agent)))->toEqualCanonicalizing([$this->bill->id, $this->paidBill->id])
        ->and(listedBills(billsPage($agent, '?building='.$oak->id)))->toBe([]);
    billsPage($agent)->assertDontSee('Oak Lodge')->assertDontSee('Oak Tenant');
    billsPage($agent, "/{$oakBill->id}")->assertNotFound();

    expect(fn () => inOrganization($this->acme, fn () => app(BillService::class)->void($agent, $oakBill, 'Not mine')))
        ->toThrow(fn (AuthorizationException $e) => expect($e->status())->toBe(404));

    // The export holds the same rows as the page.
    $rows = exportRows($agent, '/bills', 'bills', 'csv');
    expect(exportColumn($rows, 'Bill number'))->toEqualCanonicalizing([$this->bill->number, $this->paidBill->number]);

    // Their own building's bills they work on; an agent with no assignment sees every building's.
    billsUpdate($agent, billsSnapshot($agent, "/{$this->bill->id}"), ['reason' => 'Duplicate'], [['void']])->assertOk();
    expect(storedBill($this->acme, $this->bill)->status)->toBe(BillStatus::Void)
        ->and(storedBill($this->acme, $oakBill)->status)->toBe(BillStatus::Unpaid)
        ->and(listedBills(billsPage(joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent))))->toHaveCount(3);
});

it('shows bills read-only while the organization is suspended, and still exports them', function () {
    $page = billsSnapshot($this->owner, "/{$this->bill->id}");
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    billsPage($this->owner)->assertOk()->assertSee('Kemi Adebayo')->assertSee('data-test="export-bills-csv"', false);
    billsPage($this->owner, "/{$this->bill->id}")->assertOk()->assertDontSee('data-test="void-bill"', false);
    billsUpdate($this->owner, $page, ['reason' => 'Mistake'], [['void']])->assertForbidden();

    expect(storedBill($this->acme, $this->bill)->status)->toBe(BillStatus::Unpaid);

    $pressed = billsUpdate($this->owner, billsSnapshot($this->owner), [], [['export', ['bills', 'csv']]])->assertOk();
    expect(LivewireHttp::redirect($pressed))->toContain('/exports/');
});

it('lets impersonating staff look at and export bills, but never void one, in either mode', function (ImpersonationMode $mode) {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    $handOff = $mode === ImpersonationMode::Write
        ? startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write)
        : startSupport($staff, $this->owner, $this->acme);
    enterSupport($handOff)->assertRedirect('/o/acme-homes');

    $list = asSupport()->get('/o/acme-homes/bills')->assertOk()->assertSee('Kemi Adebayo')->assertSee('data-test="export-bills-csv"', false);
    $show = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/bills/{$this->bill->id}")->assertOk()->assertDontSee('data-test="void-bill"', false));

    freshRequest();
    LivewireHttp::update(test(), $show, ['reason' => 'Mistake'], [['void']])->assertForbidden();
    freshRequest();
    $pressed = LivewireHttp::update(test(), LivewireHttp::snapshot($list), [], [['export', ['bills', 'csv']]])->assertOk();

    expect(LivewireHttp::redirect($pressed))->toContain('/exports/')
        ->and(storedBill($this->acme, $this->bill)->status)->toBe(BillStatus::Unpaid)
        ->and(AuditEntry::query()->where('event', 'bill.voided')->exists())->toBeFalse();

    if ($mode === ImpersonationMode::Write) {
        expect(AuditEntry::query()->where('event', 'impersonation.blocked')->where('subject_id', $handOff->session->id)->exists())->toBeTrue();
    }
})->with([
    'read-only' => [ImpersonationMode::ReadOnly],
    'write' => [ImpersonationMode::Write],
]);
