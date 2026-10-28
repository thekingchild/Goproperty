<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Jobs\RenderInvoice;
use Modules\Billing\Models\Bill;
use Modules\Leasing\Jobs\RenderLeaseContract;
use Modules\Leasing\Models\Lease;
use Modules\Payments\Jobs\RenderReceipt;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\OwnerPortal\OwnerLinks;
use Tests\Support\LivewireHttp;

/*
 * The owner portal's test world (P03-15). Ngozi Eze owns buildings managed
 * by two organizations and follows them on one account:
 * - Adeyemi Realty (acme-homes) manages her Palm Court: Flat 3B let to Kemi
 *   Adebayo (one bill), Flat 4C vacant;
 * - Bello Homes (other-realty) manages her Lagoon View: Suite 2 let to Musa
 *   Danjuma (one bill).
 * Everything else must NOT show to her, with fixed names, phones and
 * emails (orchestrator brief): Femi Bakare's Cedar House at Adeyemi (Flat 1
 * let to Tunde Bakare, his own portal account), Adeyemi's own Office Block
 * (no owner), and Bello's Harbour Point of another owner (Suite 9 let to
 * Foreign Tenant).
 */

require_once __DIR__.'/../../../../Billing/tests/Feature/invoice-helpers.php';
require_once __DIR__.'/../Owners/helpers.php';

/** Builds the world on the current test. */
function ownerWorld(): void
{
    $test = test();
    (function () {
        Queue::fake([RenderInvoice::class, RenderReceipt::class, RenderLeaseContract::class]);
        seedOrganizationRoles();
        withoutAccountNameLookups();
        $this->travelTo('2026-12-25 09:00:00');

        $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
        $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes Ltd']);
        $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com']), $this->acme);
        $this->otherOwner = joinAs(User::factory()->create(['name' => 'Chidi Owner', 'email' => 'chidi.owner@example.com']), $this->other);
        invoiceBranding($this->acme, $this->owner, ['tradingName' => 'Adeyemi Realty', 'primaryColor' => '#0E6B5C']);
        invoiceBranding($this->other, $this->otherOwner, ['tradingName' => 'Bello Homes', 'primaryColor' => '#7A2E8C', 'invoicePrefix' => 'INV-BLH', 'receiptPrefix' => 'RCT-BLH', 'leasePrefix' => 'LS-BLH']);

        // Ngozi follows her buildings at both organizations on one account.
        $this->ngozi = User::factory()->create(['name' => 'Ngozi Eze', 'email' => 'ngozi.eze@example.com', 'phone' => '+2348051112222']);
        $this->ngoziAtAcme = ownerIn($this->acme, ['type' => 'person', 'first_name' => 'Ngozi', 'last_name' => 'Eze', 'email' => 'ngozi.eze@example.com', 'phone' => '+2348051112222']);
        $this->ngoziAtBello = ownerIn($this->other, ['type' => 'person', 'first_name' => 'Ngozi', 'last_name' => 'Eze', 'email' => 'ngozi.eze@example.com', 'phone' => '+2348051112222']);
        linkOwner($this->acme, $this->ngoziAtAcme, $this->ngozi);
        linkOwner($this->other, $this->ngoziAtBello, $this->ngozi);

        $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court', 'address' => '22 Fola Osibo Road, Lekki Phase 1', 'owner_profile_id' => $this->ngoziAtAcme->id]);
        $this->flat3b = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 3B']);
        $this->flat4c = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 4C']);
        $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com']);
        $this->lease = activeLease($this->acme, $this->owner, $this->flat3b, $this->kemi);

        $this->lagoon = buildingIn($this->other, ['name' => 'Lagoon View', 'slug' => 'lagoon-view', 'address' => '4 Ozumba Mbadiwe Avenue, Victoria Island', 'owner_profile_id' => $this->ngoziAtBello->id]);
        $musa = tenantIn($this->other, ['first_name' => 'Musa', 'last_name' => 'Danjuma', 'phone' => '+2348036667777', 'email' => 'musa.danjuma@example.com']);
        $this->belloLease = activeLease($this->other, $this->otherOwner, unitIn($this->other, $this->lagoon, ['unit_label' => 'Suite 2']), $musa, ['rent' => '900,000']);

        // Femi Bakare's Cedar House at Adeyemi: another owner's building.
        $this->femi = User::factory()->create(['name' => 'Femi Bakare', 'email' => 'femi.bakare@example.com', 'phone' => '+2348059998888']);
        $this->femiAtAcme = ownerIn($this->acme, ['type' => 'person', 'first_name' => 'Femi', 'last_name' => 'Bakare', 'email' => 'femi.bakare@example.com', 'phone' => '+2348059998888']);
        linkOwner($this->acme, $this->femiAtAcme, $this->femi);
        $this->cedar = buildingIn($this->acme, ['name' => 'Cedar House', 'slug' => 'cedar-house', 'address' => '9 Bourdillon Road, Ikoyi', 'owner_profile_id' => $this->femiAtAcme->id]);
        $tunde = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348034445555', 'email' => 'tunde.bakare@example.com']);
        $this->cedarLease = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->cedar, ['unit_label' => 'Flat 1']), $tunde, ['rent' => '1,800,000']);

        // Adeyemi's own Office Block (no owner) and Bello's Harbour Point (another owner).
        $this->office = buildingIn($this->acme, ['name' => 'Office Block', 'slug' => 'office-block', 'address' => '1 Marina Road, Lagos Island']);
        $harbourOwner = ownerIn($this->other, ['type' => 'company', 'company_name' => 'Harbour Holdings Ltd', 'email' => 'harbour@example.com', 'phone' => '+2348057776666']);
        $this->harbour = buildingIn($this->other, ['name' => 'Harbour Point', 'slug' => 'harbour-point', 'address' => '7 Kingsway Road, Ikoyi', 'owner_profile_id' => $harbourOwner->id]);
        $foreign = tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Tenant', 'phone' => '+2348035550000', 'email' => 'foreign.tenant@example.com']);
        $this->harbourLease = activeLease($this->other, $this->otherOwner, unitIn($this->other, $this->harbour, ['unit_label' => 'Suite 9']), $foreign, ['rent' => '2,400,000']);

        runBillGeneration();

        $this->bill = leaseBillOf($this->acme, $this->lease);
        $this->belloBill = leaseBillOf($this->other, $this->belloLease);
        $this->cedarBill = leaseBillOf($this->acme, $this->cedarLease);
        $this->harbourBill = leaseBillOf($this->other, $this->harbourLease);
    })->call($test);
}

/** Links the owner profile to the user as an accepted owner invitation does: the link and `property-owner` there. */
function linkOwner(Organization $organization, OwnerProfile $owner, User $user): void
{
    inOrganization($organization, fn () => app(OwnerLinks::class)->link($owner, $user, $organization));
}

/** The lease's (only) bill. */
function leaseBillOf(Organization $organization, Lease $lease): Bill
{
    return inOrganization($organization, fn (): Bill => Bill::query()->where('lease_id', $lease->id)->sole());
}

/** An owner portal page as the user, in a fresh request. */
function ownerPortalPage(User $user, string $path = ''): TestResponse
{
    billsFreshRequest();

    return test()->actingAs($user)->get('/owner'.$path);
}

/** The ids a rendered page lists for data-$attribute (building, lease, bill, organization). */
function ownerPortalIds(TestResponse $page, string $attribute): array
{
    preg_match_all('/data-'.$attribute.'="(\d+)"/', (string) $page->getContent(), $matches);

    $ids = array_values(array_unique(array_map('intval', $matches[1])));
    sort($ids);

    return $ids;
}

/** One Livewire call to a component of a page the user opened. */
function ownerPortalCall(User $user, string $path, string $method, array $params = []): TestResponse
{
    $snapshot = LivewireHttp::snapshot(ownerPortalPage($user, $path)->assertOk());
    billsFreshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, [], [[$method, $params]]);
}
