<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Bills\BillService;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Leasing\Models\Lease;
use Modules\OwnerStatements\Models\OwnerStatementSettlement;
use Modules\OwnerStatements\Statements\MonthStatement;
use Modules\OwnerStatements\Statements\OwnerStatements;
use Modules\OwnerStatements\Statements\Settlements;
use Modules\OwnerStatements\Statements\StatementMonth;
use Modules\OwnerStatements\Statements\StatementTermsBook;
use Modules\Payments\Credit\TenantCredit;
use Modules\Payments\Models\Payment;
use Modules\Properties\Models\OwnerProfile;
use Tests\Support\LivewireHttp;

/*
 * The owner statements' test world (P08-05), on the owner portal's
 * (P03-15: Ngozi Eze owns Palm Court at Adeyemi Realty and Lagoon View at
 * Bello Homes; Femi Bakare owns Cedar House at Adeyemi; Harbour Point at
 * Bello is another owner's). statementWorld() adds Ngozi's second building
 * at Adeyemi and a January 2027 of money, everything through the real
 * writers (LeaseService, the scheduled bill generation, PaymentService,
 * ReceiptVoids, TenantCredit, ExpenseBook), with the hand-computed
 * figures in the figures test:
 *
 * Marina Heights (Ngozi, Adeyemi): Unit 1 let to Bisi Adeyinka (₦200,000
 * monthly, security levy ₦25,000 monthly), Unit 2 let to Chuka Obi
 * (₦150,000 monthly), from 1 January 2027.
 *
 * - 25 Dec 2026: January's bills issued (Palm Court Flat 3B's annual rent
 *   of ₦1,500,000 by the owner portal's world; Marina's rents and levy).
 * - 5 Jan: Kemi pays Flat 3B's ₦1,500,000 in full.
 * - 6 Jan: Bisi pays ₦100,000 of Unit 1's ₦200,000 January rent (a part payment).
 * - 7 Jan: Bisi pays the ₦25,000 levy (utilities).
 * - 8 Jan: Chuka pays ₦180,000 for Unit 2's ₦150,000 rent: ₦30,000 to credit.
 * - 9 Jan: Tunde pays Cedar House (Femi's) ₦1,800,000; Musa pays Lagoon
 *   View (Ngozi at Bello) ₦900,000.
 * - 10 Jan: Chuka's ₦30,000 credit refunded to him (netted: never counted).
 * - 12 Jan: a ₦40,000 plumbing expense at Marina, charged to the owner;
 *   14 Jan: a ₦60,000 expense at Palm Court, not charged.
 * - 20 Jan: Chuka pays ₦20,000 ahead, with nothing owed: credit.
 * - 25 Jan: February's bills issued: Chuka's ₦20,000 credit is used for
 *   his February rent (counted in January); Bisi's February levy is
 *   voided on 26 Jan (listed, VOID, no money).
 */

require_once __DIR__.'/../../../Properties/tests/Feature/OwnerPortal/owner-world.php';
require_once __DIR__.'/../../../Billing/tests/Feature/utility-bill-helpers.php';
require_once __DIR__.'/../../../Payments/tests/Feature/receipt-helpers.php';
require_once __DIR__.'/../../../Expenses/tests/Feature/helpers.php';

/** The owner portal's world, plus Ngozi's Marina Heights and its tenants (bills for January issued). */
function statementBaseWorld(): void
{
    ownerWorld();

    (function () {
        $this->marina = buildingIn($this->acme, ['name' => 'Marina Heights', 'slug' => 'marina-heights', 'address' => '3 Akin Adesola Street, Victoria Island', 'owner_profile_id' => $this->ngoziAtAcme->id]);
        $this->unit1 = unitIn($this->acme, $this->marina, ['unit_label' => 'Unit 1']);
        $this->unit2 = unitIn($this->acme, $this->marina, ['unit_label' => 'Unit 2']);
        $this->bisi = tenantIn($this->acme, ['first_name' => 'Bisi', 'last_name' => 'Adeyinka', 'phone' => '+2348031230001', 'email' => 'bisi.adeyinka@example.com']);
        $this->chuka = tenantIn($this->acme, ['first_name' => 'Chuka', 'last_name' => 'Obi', 'phone' => '+2348031230002', 'email' => 'chuka.obi@example.com']);
        $this->bisiLease = monthlyLease($this->acme, $this->owner, $this->unit1, $this->bisi, ['rent' => '200,000']);
        $this->chukaLease = monthlyLease($this->acme, $this->owner, $this->unit2, $this->chuka, ['rent' => '150,000']);
        $this->levy = utilityOn($this->acme, $this->owner, $this->unit1, utilityTypeIn($this->acme, $this->owner));
        runBillGeneration();
    })->call(test());
}

/** statementBaseWorld() and January 2027's money (see the file comment); the clock ends on 26 Jan 2027. */
function statementWorld(): void
{
    statementBaseWorld();

    (function () {
        $this->bisiJanRent = rentBillFor($this->acme, $this->bisiLease, '2027-01-01');
        $this->chukaJanRent = rentBillFor($this->acme, $this->chukaLease, '2027-01-01');
        $this->bisiJanLevy = levyBillFor($this->acme, $this->levy, '2027-01-01');

        $this->travelTo('2027-01-05 10:00:00');
        $this->kemiPayment = confirmedPayment($this->acme, $this->owner, $this->kemi, '1,500,000', [], [$this->bill->id => '1,500,000']);

        $this->travelTo('2027-01-06 10:00:00');
        $this->bisiPart = confirmedPayment($this->acme, $this->owner, $this->bisi, '100,000', [], [$this->bisiJanRent->id => '100,000']);

        $this->travelTo('2027-01-07 10:00:00');
        confirmedPayment($this->acme, $this->owner, $this->bisi, '25,000', [], [$this->bisiJanLevy->id => '25,000']);

        $this->travelTo('2027-01-08 10:00:00');
        $this->chukaPayment = confirmedPayment($this->acme, $this->owner, $this->chuka, '180,000');

        $this->travelTo('2027-01-09 10:00:00');
        confirmedPayment($this->acme, $this->owner, $this->cedarLease->tenant_profile_id, '1,800,000', [], [$this->cedarBill->id => '1,800,000']);
        confirmedPayment($this->other, $this->otherOwner, $this->belloLease->tenant_profile_id, '900,000', [], [$this->belloBill->id => '900,000']);

        $this->travelTo('2027-01-10 10:00:00');
        inOrganization($this->acme, fn () => DB::transaction(fn () => app(TenantCredit::class)->closeForHandover($this->chuka->id, Money::naira(30_000), true, $this->owner, 'Refunded to the tenant by transfer')));

        $this->travelTo('2027-01-12 10:00:00');
        $this->chargedExpense = recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->marina->id, 'unitId' => (string) $this->unit2->id, 'description' => 'Replaced the bathroom tap', 'amount' => '40,000', 'chargedToOwner' => true]);

        $this->travelTo('2027-01-14 10:00:00');
        $this->ownExpense = recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->palm->id, 'description' => 'Office printer for the site', 'amount' => '60,000']);

        // Paid ahead with nothing owed: credit, counted only once it is used.
        $this->travelTo('2027-01-20 10:00:00');
        confirmedPayment($this->acme, $this->owner, $this->chuka, '20,000');

        // February's bills, on their billing day (lead window: 7 days).
        $this->travelTo('2027-01-25 00:10:00');
        runBillGeneration();
        $this->bisiFebLevy = levyBillFor($this->acme, $this->levy, '2027-02-01');
        $this->chukaFebRent = rentBillFor($this->acme, $this->chukaLease, '2027-02-01');

        $this->travelTo('2027-01-26 10:00:00');
        inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, storedBill($this->acme, $this->bisiFebLevy), 'Levy waived for February'));
    })->call(test());
}

/** The lease's rent bill for the period starting on $start. */
function rentBillFor(Organization $organization, Lease $lease, string $start): Bill
{
    return inOrganization($organization, fn (): Bill => Bill::query()->where('lease_id', $lease->id)->where('type', 'rent')->whereDate('period_start', $start)->sole());
}

/** The utility setting's bill for the period starting on $start. */
function levyBillFor(Organization $organization, UnitUtilitySetting $setting, string $start): Bill
{
    return inOrganization($organization, fn (): Bill => Bill::query()->where('unit_utility_setting_id', $setting->id)->whereDate('period_start', $start)->sole());
}

/** The owner's month as the pages see it, inside the organization. */
function statementOf(Organization $organization, OwnerProfile $owner, string $month): MonthStatement
{
    return inOrganization($organization, fn (): MonthStatement => app(OwnerStatements::class)->month($owner->id, StatementMonth::fromKey($month) ?? throw new LogicException("Bad month {$month}")));
}

/** The owner's terms changed through the writer. */
function setStatementTerms(Organization $organization, User $by, OwnerProfile $owner, array $input): void
{
    inOrganization($organization, fn () => app(StatementTermsBook::class)->change($by, $owner->id, $input + ['commission' => '', 'utilitiesToOwner' => false, 'shareTenantContacts' => false]));
}

/** The month marked settled through the writer. */
function settleStatement(Organization $organization, User $by, OwnerProfile $owner, string $month, string $paidOn, string $reference = 'TRF-OWNER-0001'): OwnerStatementSettlement
{
    return inOrganization($organization, fn (): OwnerStatementSettlement => app(Settlements::class)->settle($by, $owner->id, StatementMonth::fromKey($month) ?? throw new LogicException('Bad month'), $paidOn, $reference));
}

/** The month marked unsettled through the writer. */
function unsettleStatement(Organization $organization, User $by, OwnerProfile $owner, string $month, string $reason = 'Paid to the wrong account; paying again'): OwnerStatementSettlement
{
    return inOrganization($organization, fn (): OwnerStatementSettlement => app(Settlements::class)->unsettle($by, $owner->id, StatementMonth::fromKey($month) ?? throw new LogicException('Bad month'), $reason));
}

/** [rent, utilities, commission, expenses, net due] in kobo, of a statement's totals or a building's part. */
function statementFigures(MonthStatement $month, ?int $buildingId = null): array
{
    $figures = $month->statement->totals;

    foreach ($month->statement->buildings as $building) {
        if ($building->propertyId === $buildingId) {
            $figures = $building->figures;
        }
    }

    return [$figures->rent->kobo, $figures->utilities->kobo, $figures->commission->kobo, $figures->expenses->kobo, $figures->netDue->kobo];
}

/** An agency statement page as the member, in a fresh request. */
function agencyStatementPage(User $user, OwnerProfile $owner, string $path = '', string $slug = 'acme-homes'): TestResponse
{
    billsFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/owners/{$owner->id}/statements".$path);
}

/** One Livewire call on an agency statement page the member opened. */
function agencyStatementCall(User $user, OwnerProfile $owner, string $path, string $method, array $updates = [], string $slug = 'acme-homes'): TestResponse
{
    $snapshot = LivewireHttp::snapshot(agencyStatementPage($user, $owner, $path, $slug)->assertOk());
    billsFreshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, $updates, [[$method, []]]);
}

/** The payment as stored now. */
function freshPayment(Organization $organization, Payment $payment): Payment
{
    return storedPayment($organization, $payment);
}
