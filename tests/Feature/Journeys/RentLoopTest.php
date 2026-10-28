<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Models\Bill;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Contracts\CycleCalculator;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\Receipt;
use Modules\Properties\Enums\UnitStatus;
use Modules\Tenants\Livewire\Portal\PortalProofForm;
use Modules\Tenants\Models\TenantProfile;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../../../Modules/Tenants/tests/Feature/Portal/portal-world.php';
require_once __DIR__.'/../../../Modules/Tenants/tests/Feature/Portal/helpers.php';
require_once __DIR__.'/../../../Modules/Payments/tests/Feature/proof-helpers.php';

/*
 * The P03 phase exit (IMPLEMENTATION_PLAN.md § 3 P03 "Phase exit"): the
 * whole rent loop, through the real pages and jobs, for a yearly, a weekly
 * and a six-monthly lease. An owner adds a building and three units (bulk),
 * a tenant, drafts and activates a lease; the scheduled `bills-generate`
 * job issues the first invoice on its billing day (time travel: not the
 * day before); the tenant, invited to the portal by email, sends a proof
 * of payment for it from the portal; the owner confirms it on the payment's
 * page; a numbered receipt is issued and the tenant sees it in the portal.
 * The next period is billed and paid the same way, and its receipt number
 * is the next one. Every person, phone and email is fixed.
 */

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('media');
    Notification::fake();
    // The documents are issued as usual; only dompdf (seconds a PDF) is held back.
    holdPortalPdfs();
    seedOrganizationRoles();
    withoutAccountNameLookups();
    $this->travelTo('2026-12-20 09:00:00');

    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com', 'phone' => '+2348020000001']), $this->acme);
    addBankAccount($this->acme, $this->owner, '0123456789', 'Adeyemi Realty Ltd');
});

afterEach(fn () => billsFreshRequest());

/** The organization's bills, oldest first. */
function journeyBills(Organization $organization): array
{
    return inOrganization($organization, fn (): array => Bill::query()->orderBy('id')->get()->all());
}

/** The tenant sends a proof for the bill from the portal's proof page (the real Livewire form). */
function journeyProof(User $tenant, Bill $bill, string $amount, int $seed): void
{
    portalPage($tenant, "/bills/{$bill->id}")->assertOk()->assertSee('Paid already?')->assertSee(route('portal.bills.proof', $bill->id), false);

    billsFreshRequest();
    test()->actingAs($tenant);
    $page = Livewire::test(PortalProofForm::class, ['bill' => $bill->id]);
    chooseProofFile($page, proofFile("transfer-{$seed}.png", $seed));

    foreach (['amount' => $amount, 'senderName' => 'Kemi Adebayo', 'bank' => 'Guaranty Trust Bank (GTBank)', 'reference' => 'Rent '.$bill->number] as $field => $value) {
        $page->set($field, $value);
    }

    $page->call('save')->assertHasNoErrors()->assertRedirect(route('portal.bills.show', $bill->id));
}

/** The owner confirms the waiting payment on its page (the real Livewire page). */
function journeyConfirm(User $owner, Payment $payment): void
{
    paymentsPage($owner, '?status=pending_confirmation')->assertOk()->assertSee('data-payment="'.$payment->id.'"', false);

    paymentsUpdate($owner, paymentsSnapshot($owner, "/{$payment->id}"), ['note' => 'Seen on the GTBank statement'], [['confirm']])
        ->assertOk()
        ->assertSee('The payment is confirmed.');
}

it('runs the rent loop from an empty organization to a receipt in the tenant portal', function (BillingCycle $cycle, string $end, string $rent, int $rentKobo) {
    $calculator = app(CycleCalculator::class);
    $start = CarbonImmutable::parse('2027-01-01');

    // 1. A building, from the add page.
    billsFreshRequest();
    $saved = LivewireHttp::update($this, buildingsSnapshot($this->owner, '/create'), buildingForm(), [['save']])->assertOk();
    expect(LivewireHttp::errors($saved))->toBe([]);
    $building = buildingsOf($this->acme)->sole();
    expect($building->name)->toBe('Palm Court')->and($building->slug)->toBe('palm-court');

    // 2. Three units in one bulk action.
    billsFreshRequest();
    $bulk = LivewireHttp::update($this, unitsSnapshot($this->owner, 'palm-court', '/bulk'), bulkForm(['count' => '3']), [['save']])->assertOk();
    expect(LivewireHttp::errors($bulk))->toBe([]);
    $units = unitsOf($this->acme);
    expect($units->pluck('unit_label')->all())->toBe(['Flat 1', 'Flat 2', 'Flat 3'])
        ->and($units->pluck('property_id')->unique()->all())->toBe([$building->id]);
    $flat = $units->first();

    // 3. A tenant, from the add page.
    $tenantSaved = LivewireHttp::update($this, tenantsSnapshot($this->owner, '/create'), tenantForm([
        'firstName' => 'Kemi', 'lastName' => 'Adebayo', 'phone' => '0803 111 2222', 'email' => 'kemi.adebayo@example.com',
        'kinName' => 'Tola Adebayo', 'kinPhone' => '0805 333 4444',
    ]), [['save']])->assertOk();
    expect(LivewireHttp::errors($tenantSaved))->toBe([]);
    $tenant = inOrganization($this->acme, fn (): TenantProfile => TenantProfile::query()->sole());
    expect($tenant->phone)->toBe('+2348031112222');

    // 4. The lease, drafted on its form (building, then unit, then terms) and activated on its page.
    $form = leasesUpdate($this->owner, leasesSnapshot($this->owner, '/create'), ['propertyId' => (string) $building->id])->assertOk();
    $form = leasesUpdate($this->owner, LivewireHttp::next($form), ['unitId' => (string) $flat->id])->assertOk();
    $drafted = leasesUpdate($this->owner, LivewireHttp::next($form), [
        'tenantId' => (string) $tenant->id,
        'billingCycle' => $cycle->value,
        'startDate' => $start->toDateString(),
        'endDate' => $end,
        'rent' => $rent,
        'deposit' => '0',
        'dueDayOffset' => '0',
    ], [['save']])->assertOk();
    expect(LivewireHttp::errors($drafted))->toBe([]);
    $lease = leasesOf($this->acme)->sole();
    expect($lease->status)->toBe(LeaseStatus::Draft)
        ->and($lease->billing_cycle)->toBe($cycle)
        ->and($lease->rent_amount_kobo->kobo)->toBe($rentKobo)
        ->and(unitStatusOf($this->acme, $flat))->toBe(UnitStatus::Reserved);

    $activated = leasesUpdate($this->owner, leasesSnapshot($this->owner, "/{$lease->id}"), [], [['activate']])->assertOk();
    expect(LivewireHttp::html($activated))->toContain('The lease is active: LS-ADR-2026-000001.');
    expect(storedLease($this->acme, $lease)->status)->toBe(LeaseStatus::Active)
        ->and(unitStatusOf($this->acme, $flat))->toBe(UnitStatus::Occupied);

    // 5. The tenant is invited to the portal by email and sets up an account.
    $token = inviteByEmail($this->owner, $tenant);
    acceptPortalInvitation($token, null, ['name' => 'Kemi Adebayo', 'password' => 'a-long-password-1'])->assertRedirect('/portal/welcome');
    $kemi = User::query()->findOrFail(freshTenant($this->acme, $tenant)->user_id);
    expect($kemi->email)->toBe('kemi.adebayo@example.com');

    // 6. The scheduler issues the first invoice on its billing day (lead 7 days), not the day before.
    $periods = calculatorPeriods($start->toDateString(), $cycle, 2);
    $firstBillingDay = $calculator->billingDate($start);
    $this->travelTo($firstBillingDay->subDay()->setTime(0, 10));
    runBillGeneration();
    expect(journeyBills($this->acme))->toBe([]);

    $this->travelTo($firstBillingDay->setTime(0, 10));
    runBillGeneration();
    runBillGeneration(); // a rerun issues nothing more
    $bills = journeyBills($this->acme);
    expect($bills)->toHaveCount(1);
    $first = $bills[0];
    expect($first->type)->toBe(BillType::Rent)
        ->and($first->lease_id)->toBe($lease->id)
        ->and($first->tenant_profile_id)->toBe($tenant->id)
        ->and($first->amount_kobo->kobo)->toBe($rentKobo)
        ->and([$first->period_start?->toDateString(), $first->period_end?->toDateString(), $first->due_date->toDateString()])->toBe($periods[0])
        ->and($first->status)->toBe(BillStatus::Unpaid)
        ->and($first->number)->toBe('INV-ADR-2026-000001');

    // The tenant sees it in the portal.
    $portalBills = portalPage($kemi, '/bills')->assertOk()->assertSee($first->number);
    expect(portalIds($portalBills, 'bill'))->toBe([$first->id]);

    // 7. On the first day of the period the tenant pays and sends a proof from the portal.
    $this->travelTo($start->setTime(9, 0));
    journeyProof($kemi, $first, $rent, 1);
    $payment = paymentsOf($this->acme)->sole();
    expect($payment->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and($payment->source)->toBe(PaymentSource::ProofUpload)
        ->and($payment->bill_id)->toBe($first->id)
        ->and($payment->amount_kobo->kobo)->toBe($rentKobo)
        ->and($payment->recorded_by_user_id)->toBe($kemi->id)
        ->and(proofsOf($this->acme))->toHaveCount(1);

    // 8. The owner confirms it on the payment's page: the bill is paid and a receipt issued.
    journeyConfirm($this->owner, $payment);
    expect(storedPayment($this->acme, $payment)->status)->toBe(PaymentStatus::Confirmed)
        ->and(allocationsOf($this->acme, $payment))->toEqual([$first->id => $rentKobo])
        ->and(storedBill($this->acme, $first)->status)->toBe(BillStatus::Paid)
        ->and(creditOf($this->acme, $tenant)->kobo)->toBe(0);
    $firstReceipt = receiptOf($this->acme, $payment);
    expect($firstReceipt->number)->toBe('RCT-ADR-2027-000001')
        ->and($firstReceipt->amount_kobo->kobo)->toBe($rentKobo)
        ->and($firstReceipt->tenant_profile_id)->toBe($tenant->id)
        ->and(storedPayment($this->acme, $payment)->receipt_id)->toBe($firstReceipt->id);

    // 9. The tenant portal shows the receipt: in "my payments" and on its own page.
    portalPage($kemi, '/payments')->assertOk()
        ->assertSee($firstReceipt->number)
        ->assertSee(route('portal.receipts.show', $firstReceipt->id), false)
        ->assertSee('For bill '.$first->number);
    portalPage($kemi, "/receipts/{$firstReceipt->id}")->assertOk()
        ->assertSee('data-receipt="'.$firstReceipt->id.'"', false)
        ->assertSee($firstReceipt->number)
        ->assertSee(Money::kobo($rentKobo)->format())
        ->assertSee('Official receipt');
    // The receipt checks out on the public verify page.
    billsFreshRequest();
    $this->get('/verify/'.$firstReceipt->verification_code)->assertOk()->assertSee($firstReceipt->number);

    // 10. The next period: billed on its own billing day (not the day before), paid and confirmed the same way.
    $secondStart = CarbonImmutable::parse($periods[1][0]);
    $secondBillingDay = $calculator->billingDate($secondStart);
    $this->travelTo($secondBillingDay->subDay()->setTime(0, 10));
    runBillGeneration();
    expect(journeyBills($this->acme))->toHaveCount(1);

    $this->travelTo($secondBillingDay->setTime(0, 10));
    runBillGeneration();
    $bills = journeyBills($this->acme);
    expect($bills)->toHaveCount(2);
    $second = $bills[1];
    expect([$second->period_start?->toDateString(), $second->period_end?->toDateString(), $second->due_date->toDateString()])->toBe($periods[1])
        ->and($second->amount_kobo->kobo)->toBe($rentKobo)
        ->and($second->status)->toBe(BillStatus::Unpaid);

    $this->travelTo($secondBillingDay->setTime(9, 0));
    journeyProof($kemi, $second, $rent, 2);
    $secondPayment = paymentsOf($this->acme)->last();
    expect($secondPayment->id)->not->toBe($payment->id)->and($secondPayment->bill_id)->toBe($second->id);
    journeyConfirm($this->owner, $secondPayment);

    expect(storedBill($this->acme, $second)->status)->toBe(BillStatus::Paid)
        ->and(allocationsOf($this->acme, $secondPayment))->toEqual([$second->id => $rentKobo]);
    $secondReceipt = receiptOf($this->acme, $secondPayment);

    // The receipt number increments: the organization's next receipt this year.
    expect($secondReceipt->number)->toBe('RCT-ADR-2027-000002')
        ->and(inOrganization($this->acme, fn (): array => Receipt::query()->orderBy('id')->pluck('number')->all()))->toBe(['RCT-ADR-2027-000001', 'RCT-ADR-2027-000002']);

    $payments = portalPage($kemi, '/payments')->assertOk()
        ->assertSee($secondReceipt->number)
        ->assertSee($firstReceipt->number)
        ->assertSee(route('portal.receipts.show', $secondReceipt->id), false);
    expect(portalIds($payments, 'receipt'))->toEqualCanonicalizing([$firstReceipt->id, $secondReceipt->id]);
    portalPage($kemi, "/receipts/{$secondReceipt->id}")->assertOk()->assertSee($secondReceipt->number);
})->with([
    'annual' => [BillingCycle::Annual, '2028-12-31', '1,500,000', 150_000_000],
    'weekly' => [BillingCycle::Weekly, '2027-03-31', '25,000', 2_500_000],
    'semi-annual' => [BillingCycle::SemiAnnual, '2027-12-31', '750,000', 75_000_000],
]);
