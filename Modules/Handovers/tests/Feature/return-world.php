<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Jobs\RenderInvoice;
use Modules\Handovers\Handovers\HandoverService;
use Modules\Handovers\Models\ManagementHandover;
use Modules\Leasing\Jobs\RenderLeaseContract;
use Modules\Payments\Jobs\RenderReceipt;
use Modules\Properties\Models\Property;

/*
 * The one-way return's test world (P04-04, BILLING_PAYMENTS.md § 7.1).
 * Every name, phone and email is fixed.
 *
 * Adeyemi Realty (`acme-homes`, an agency on Starter; owner Ada Owner,
 * administrator Tola Admin) holds, without any handover:
 * - Harbour Point (owner record "Ngozi Eze", linked to Ngozi's account
 *   through the owner portal; reference B-HARB): Flat A let to Tunde
 *   Bakare, who paid ₦100,000 more than his first bill (credit); Flat B
 *   let to Kemi Adebayo, who has not paid her first bill; Flat C vacant.
 * - Garden Court (owner record "Ngozi Eze"): Garden 1, vacant.
 * - Lone Tower (owner record "Bayo Bello", never invited to the portal):
 *   Lone 1.
 * - Office Block (no owner record): Office 1–3.
 *
 * Eze Properties (`eze-properties`, Ngozi Eze's own organization, a
 * property owner's; Emeka Eze its administrator) has Palm Court (Flat 1).
 * Bello Homes (`bello-homes`, another landlord's organization, Bayo Bello's)
 * has nothing to do with Harbour Point.
 */

require_once __DIR__.'/helpers.php';

/** Builds the return world on the current test. */
function returnWorld(): void
{
    $test = test();
    (function () {
        Queue::fake([RenderInvoice::class, RenderReceipt::class, RenderLeaseContract::class]);
        Storage::fake('media');
        Notification::fake();
        seedOrganizationRoles();
        withoutAccountNameLookups();
        $this->travelTo('2026-12-20 09:00:00');

        $this->landlord = Organization::factory()->create(['slug' => 'eze-properties', 'name' => 'Eze Properties', 'type' => OrganizationType::PropertyOwner, 'platform_covered' => true, 'address' => '12 Admiralty Way, Lekki', 'state' => 'Lagos']);
        $this->ngozi = joinAs(User::factory()->create(['name' => 'Ngozi Eze', 'email' => 'ngozi.eze@example.com', 'phone' => '+2348051112222']), $this->landlord);
        $this->emeka = joinAs(User::factory()->create(['name' => 'Emeka Eze', 'email' => 'emeka.eze@example.com', 'phone' => '+2348051113333']), $this->landlord, OrganizationRole::Admin);
        $this->palm = buildingIn($this->landlord, ['name' => 'Palm Court', 'slug' => 'palm-court', 'address' => '22 Fola Osibo Road, Lekki Phase 1']);
        $this->palmFlat = unitIn($this->landlord, $this->palm, ['unit_label' => 'Flat 1']);

        $this->other = Organization::factory()->create(['slug' => 'bello-homes', 'name' => 'Bello Homes', 'type' => OrganizationType::PropertyOwner, 'platform_covered' => true]);
        $this->bayo = joinAs(User::factory()->create(['name' => 'Bayo Bello', 'email' => 'bayo.bello@example.com', 'phone' => '+2348051114444']), $this->other);

        $this->agency = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd', 'type' => OrganizationType::Agency, 'signup_plan_key' => 'starter']);
        $this->ada = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com', 'phone' => '+2348020000001']), $this->agency);
        $this->tola = joinAs(User::factory()->create(['name' => 'Tola Admin', 'email' => 'tola.admin@example.com', 'phone' => '+2348020000003']), $this->agency, OrganizationRole::Admin);
        invoiceBranding($this->agency, $this->ada, ['tradingName' => 'Adeyemi Realty']);

        $this->ngoziAtAgency = ownerIn($this->agency, ['type' => 'person', 'first_name' => 'Ngozi', 'last_name' => 'Eze', 'email' => 'ngozi.eze@example.com', 'phone' => '+2348051112222']);
        linkOwner($this->agency, $this->ngoziAtAgency, $this->ngozi);
        $this->bayoAtAgency = ownerIn($this->agency, ['type' => 'person', 'first_name' => 'Bayo', 'last_name' => 'Bello', 'email' => 'bayo.bello@example.com', 'phone' => '+2348051114444']);

        $this->harbour = buildingIn($this->agency, ['name' => 'Harbour Point', 'slug' => 'harbour-point', 'address' => '5 Marina Road, Lagos Island', 'owner_profile_id' => $this->ngoziAtAgency->id, 'external_ref' => 'B-HARB']);
        $this->flatA = unitIn($this->agency, $this->harbour, ['unit_label' => 'Flat A']);
        $this->flatB = unitIn($this->agency, $this->harbour, ['unit_label' => 'Flat B']);
        $this->flatC = unitIn($this->agency, $this->harbour, ['unit_label' => 'Flat C']);
        $this->garden = buildingIn($this->agency, ['name' => 'Garden Court', 'slug' => 'garden-court', 'address' => '8 Awolowo Road, Ikoyi', 'owner_profile_id' => $this->ngoziAtAgency->id]);
        $this->gardenUnit = unitIn($this->agency, $this->garden, ['unit_label' => 'Garden 1']);
        $this->lone = buildingIn($this->agency, ['name' => 'Lone Tower', 'slug' => 'lone-tower', 'address' => '2 Broad Street, Lagos Island', 'owner_profile_id' => $this->bayoAtAgency->id]);
        unitIn($this->agency, $this->lone, ['unit_label' => 'Lone 1']);
        $this->office = buildingIn($this->agency, ['name' => 'Office Block', 'slug' => 'office-block', 'address' => '1 Marina Road, Lagos Island']);

        foreach (range(1, 3) as $n) {
            unitIn($this->agency, $this->office, ['unit_label' => "Office {$n}"]);
        }

        $this->tunde = tenantIn($this->agency, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348034445555', 'email' => 'tunde.bakare@example.com']);
        $this->kemi = tenantIn($this->agency, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com']);
        $this->tundeLease = activeLease($this->agency, $this->ada, $this->flatA, $this->tunde);
        $this->kemiLease = activeLease($this->agency, $this->ada, $this->flatB, $this->kemi, ['rent' => '1,200,000', 'deposit' => '120,000']);

        // History: the first rent bills, Tunde's payment with ₦100,000 over (credit).
        $this->travelTo('2026-12-25 09:00:00');
        runBillGeneration();
        $this->tundePayment = confirmedPayment($this->agency, $this->ada, $this->tunde, '1,600,000');

        $this->travelTo('2027-01-04 09:00:00');
    })->call($test);
}

/** $by (in the agency $from) offers these buildings back to the landlord's organization $to. */
function offerReturn(User $by, Organization $from, Organization $to, array $buildings): ManagementHandover
{
    return inOrganization($from, fn (): ManagementHandover => app(HandoverService::class)->offerReturn($by, $to, array_map(fn (Property|int $building): int => $building instanceof Property ? $building->id : $building, $buildings)));
}
