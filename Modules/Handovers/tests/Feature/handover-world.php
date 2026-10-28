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
use Modules\Leasing\Jobs\RenderLeaseContract;
use Modules\Payments\Jobs\RenderReceipt;
use Modules\Properties\Buildings\PropertyPhotos;
use Modules\Properties\Models\Unit;

/*
 * The management handover's test world (P03-14). Every name, phone and
 * email is fixed.
 *
 * Eze Properties (`eze-properties`, a property owner's organization) is
 * Ngozi Eze's; Sade Caretaker is its agent, assigned to Palm Court.
 * - Palm Court (owned by its owner record "Ngozi Eze", import reference
 *   B-PALM, two photos): Flat 1 (reference U-1) let to Tunde Bakare, who
 *   paid ₦100,000 more than his first bill (credit) and holds water
 *   (metered, two readings, one with a meter photo); Flat 2 let to Kemi
 *   Adebayo, who has not paid her first bill and pays the security levy;
 *   Flat 3 vacant; Flat 4 archived.
 * - Lagoon View: Suite 1 let to Musa Danjuma; Suite 2 held by a draft lease
 *   for Bisi Ade.
 * - Cedar House (stays): Unit A let to Femi Okafor.
 * - An expense on Palm Court; the first rent bills issued on 25 December,
 *   Tunde's receipt.
 *
 * Adeyemi Realty (`acme-homes`, an agency on the Starter plan) is Ada
 * Owner's: Office Block with 8 units and its own "Palm Court" (slug
 * palm-court) with Flat 9 (reference U-1), so 9 billable units; its own
 * tenant record for Kemi Adebayo (same phone) and a Water type (code
 * WATER).
 *
 * The handover offers Palm Court and Lagoon View: 5 billable units (Flat 4
 * is archived), so Adeyemi's bill goes from ₦10,000 to ₦12,800 a month.
 */

require_once __DIR__.'/../../../Payments/tests/Feature/helpers.php';
require_once __DIR__.'/../../../Payments/tests/Feature/receipt-helpers.php';
require_once __DIR__.'/../../../Billing/tests/Feature/invoice-helpers.php';
require_once __DIR__.'/../../../Billing/tests/Feature/utility-helpers.php';
require_once __DIR__.'/../../../Expenses/tests/Feature/helpers.php';
require_once __DIR__.'/../../../Properties/tests/Feature/OwnerPortal/owner-world.php';
require_once __DIR__.'/../../../../tests/Support/organization-settings.php';

/** Builds the world on the current test. */
function handoverWorld(): void
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
        $this->caretaker = joinAs(User::factory()->create(['name' => 'Sade Caretaker', 'email' => 'sade.caretaker@example.com', 'phone' => '+2348052223333']), $this->landlord, OrganizationRole::Agent);
        invoiceBranding($this->landlord, $this->ngozi, ['tradingName' => 'Eze Properties', 'invoicePrefix' => 'INV-EZE', 'receiptPrefix' => 'RCT-EZE', 'leasePrefix' => 'LS-EZE']);

        $this->agency = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd', 'type' => OrganizationType::Agency, 'signup_plan_key' => 'starter']);
        $this->ada = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com', 'phone' => '+2348020000001']), $this->agency);
        invoiceBranding($this->agency, $this->ada, ['tradingName' => 'Adeyemi Realty']);

        // The landlord's buildings.
        $this->ngoziRecord = ownerIn($this->landlord, ['type' => 'person', 'first_name' => 'Ngozi', 'last_name' => 'Eze', 'email' => 'ngozi.eze@example.com', 'phone' => '+2348051112222']);
        $this->palm = buildingIn($this->landlord, ['name' => 'Palm Court', 'slug' => 'palm-court', 'address' => '22 Fola Osibo Road, Lekki Phase 1', 'owner_profile_id' => $this->ngoziRecord->id, 'external_ref' => 'B-PALM']);
        $this->flat1 = unitIn($this->landlord, $this->palm, ['unit_label' => 'Flat 1', 'external_ref' => 'U-1']);
        $this->flat2 = unitIn($this->landlord, $this->palm, ['unit_label' => 'Flat 2']);
        $this->flat3 = unitIn($this->landlord, $this->palm, ['unit_label' => 'Flat 3']);
        $this->flat4 = unitIn($this->landlord, $this->palm, ['unit_label' => 'Flat 4']);
        inOrganization($this->landlord, fn () => Unit::query()->findOrFail($this->flat4->id)->delete());
        $this->photos = inOrganization($this->landlord, fn (): array => app(PropertyPhotos::class)->add($this->ngozi, $this->palm, [mediaImage('front.jpg'), mediaImage('garden.jpg')]));
        assignIn($this->landlord, $this->caretaker, $this->palm);

        $this->lagoon = buildingIn($this->landlord, ['name' => 'Lagoon View', 'slug' => 'lagoon-view', 'address' => '4 Ozumba Mbadiwe Avenue, Victoria Island']);
        $this->suite1 = unitIn($this->landlord, $this->lagoon, ['unit_label' => 'Suite 1']);
        $this->suite2 = unitIn($this->landlord, $this->lagoon, ['unit_label' => 'Suite 2']);

        $this->cedar = buildingIn($this->landlord, ['name' => 'Cedar House', 'slug' => 'cedar-house', 'address' => '9 Bourdillon Road, Ikoyi']);
        $this->unitA = unitIn($this->landlord, $this->cedar, ['unit_label' => 'Unit A']);

        $this->tunde = tenantIn($this->landlord, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348034445555', 'email' => 'tunde.bakare@example.com']);
        $this->kemi = tenantIn($this->landlord, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com']);
        $this->musa = tenantIn($this->landlord, ['first_name' => 'Musa', 'last_name' => 'Danjuma', 'phone' => '+2348036667777', 'email' => 'musa.danjuma@example.com']);
        $this->bisi = tenantIn($this->landlord, ['first_name' => 'Bisi', 'last_name' => 'Ade', 'phone' => '+2348037778888', 'email' => 'bisi.ade@example.com']);
        $this->femi = tenantIn($this->landlord, ['first_name' => 'Femi', 'last_name' => 'Okafor', 'phone' => '+2348039990000', 'email' => 'femi.okafor@example.com']);

        $this->tundeLease = activeLease($this->landlord, $this->ngozi, $this->flat1, $this->tunde);
        $this->kemiLease = activeLease($this->landlord, $this->ngozi, $this->flat2, $this->kemi, ['rent' => '1,200,000', 'deposit' => '120,000']);
        $this->musaLease = activeLease($this->landlord, $this->ngozi, $this->suite1, $this->musa, ['rent' => '900,000', 'deposit' => '0']);
        $this->bisiDraft = draftLease($this->landlord, $this->ngozi, $this->suite2, $this->bisi, ['rent' => '800,000', 'deposit' => '0']);
        $this->femiLease = activeLease($this->landlord, $this->ngozi, $this->unitA, $this->femi, ['rent' => '2,000,000', 'deposit' => '0']);

        // Utilities on Palm Court.
        $water = utilityTypeIn($this->landlord, $this->ngozi, ['name' => 'Water', 'code' => 'WATER', 'chargingMode' => 'metered', 'unitOfMeasure' => 'cubic_metre', 'amount' => '500']);
        $security = utilityTypeIn($this->landlord, $this->ngozi);
        $this->water = utilityOn($this->landlord, $this->ngozi, $this->flat1, $water, ['effectiveFrom' => '2027-01-01', 'startingReading' => '100']);
        $this->security = utilityOn($this->landlord, $this->ngozi, $this->flat2, $security, ['effectiveFrom' => '2027-01-01']);

        // History: the first rent bills, Tunde's payment with ₦100,000 over (credit), an expense.
        $this->travelTo('2026-12-25 09:00:00');
        runBillGeneration();
        $this->tundePayment = confirmedPayment($this->landlord, $this->ngozi, $this->tunde, '1,600,000');
        $this->expense = recordExpense($this->landlord, $this->ngozi, ['propertyId' => (string) $this->palm->id, 'description' => 'Gate repair'], [receiptFile('gate.png')]);

        // The agency's own buildings, tenant and water type.
        $office = buildingIn($this->agency, ['name' => 'Office Block', 'slug' => 'office-block', 'address' => '1 Marina Road, Lagos Island']);

        foreach (range(1, 8) as $n) {
            unitIn($this->agency, $office, ['unit_label' => "Office {$n}"]);
        }

        $this->agencyPalm = buildingIn($this->agency, ['name' => 'Palm Court', 'slug' => 'palm-court', 'address' => '3 Glover Road, Ikoyi', 'external_ref' => 'B-PALM']);
        unitIn($this->agency, $this->agencyPalm, ['unit_label' => 'Flat 9', 'external_ref' => 'U-1']);
        $this->agencyKemi = tenantIn($this->agency, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.a@example.com']);
        $this->agencyWater = utilityTypeIn($this->agency, $this->ada, ['name' => 'Water supply', 'code' => 'WATER', 'chargingMode' => 'metered', 'unitOfMeasure' => 'cubic_metre', 'amount' => '450']);

        $this->travelTo('2027-01-03 09:00:00');
        $this->firstReading = readMeter($this->landlord, $this->ngozi, $this->water, '2027-01-02', '104.5');
        $this->photoReading = readMeter($this->landlord, $this->ngozi, $this->water, '2027-01-03', '106', photo: mediaImage('meter.jpg'));
    })->call($test);
}
