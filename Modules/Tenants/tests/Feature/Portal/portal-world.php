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

/*
 * The tenant portal's test world (P03-11a, shared with P03-11b's tests):
 * Kemi rents Flat 3B at Palm Court from Adeyemi Realty (acme-homes) through
 * her portal account; her neighbour Tunde rents Flat 4C there with his own;
 * Bello Homes (other-realty) has its own tenant at Foreign Court. Each
 * lease has one bill. Fixed names, phones and emails for every person and
 * building that must NOT show (orchestrator brief).
 */

require_once __DIR__.'/../../../../Billing/tests/Feature/invoice-helpers.php';

/** Builds the world on the current test (its properties: acme, other, owner, otherOwner, kemi, kemiAtAcme, lease, tunde, neighbourLease, foreignLease, bill, neighbourBill, foreignBill). */
function portalWorld(): void
{
    $test = test();
    (function () {
        holdPortalPdfs();
        seedOrganizationRoles();
        withoutAccountNameLookups();
        $this->travelTo('2026-12-25 09:00:00');

        $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
        $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes Ltd']);
        $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com']), $this->acme);
        $this->otherOwner = joinAs(User::factory()->create(['name' => 'Chidi Owner', 'email' => 'chidi.owner@example.com']), $this->other);
        invoiceBranding($this->acme, $this->owner, ['tradingName' => 'Adeyemi Realty', 'primaryColor' => '#0E6B5C']);
        invoiceBranding($this->other, $this->otherOwner, ['tradingName' => 'Bello Homes', 'primaryColor' => '#7A2E8C', 'invoicePrefix' => 'INV-BLH', 'receiptPrefix' => 'RCT-BLH', 'leasePrefix' => 'LS-BLH', 'email' => 'accounts@bellohomes.ng']);
        addBankAccount($this->acme, $this->owner, '0123456789', 'Adeyemi Realty Ltd');
        addBankAccount($this->other, $this->otherOwner, '0987654321', 'Bello Homes Ltd');

        // Kemi rents Flat 3B at Palm Court from Acme, through her portal account.
        $this->kemi = User::factory()->create(['name' => 'Kemi Adebayo', 'email' => 'kemi.adebayo@example.com', 'phone' => '+2348031112222']);
        $palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court', 'address' => '22 Fola Osibo Road, Lekki Phase 1', 'state' => 'Lagos']);
        $this->kemiAtAcme = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com', 'user_id' => $this->kemi->id]);
        $this->lease = activeLease($this->acme, $this->owner, unitIn($this->acme, $palm, ['unit_label' => 'Flat 3B']), $this->kemiAtAcme);

        // Her neighbour Tunde rents Flat 4C there, with his own account.
        $this->tunde = User::factory()->create(['name' => 'Tunde Bakare', 'email' => 'tunde.bakare@example.com', 'phone' => '+2348034445555']);
        $tunde = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348034445555', 'email' => 'tunde.bakare@example.com', 'user_id' => $this->tunde->id]);
        $this->neighbourLease = activeLease($this->acme, $this->owner, unitIn($this->acme, $palm, ['unit_label' => 'Flat 4C']), $tunde, ['rent' => '1,800,000']);

        // Bello Homes has its own tenant at Foreign Court.
        $foreign = tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Tenant', 'phone' => '+2348035550000', 'email' => 'foreign.tenant@example.com']);
        $this->foreignLease = activeLease($this->other, $this->otherOwner, unitIn($this->other, buildingIn($this->other, ['name' => 'Foreign Court', 'slug' => 'foreign-court']), ['unit_label' => 'Suite 9']), $foreign, ['rent' => '2,400,000']);

        runBillGeneration();

        $this->bill = portalBillOf($this->acme, $this->lease);
        $this->neighbourBill = portalBillOf($this->acme, $this->neighbourLease);
        $this->foreignBill = portalBillOf($this->other, $this->foreignLease);
    })->call($test);
}

/**
 * Holds back the document PDF jobs (invoice, receipt, lease contract):
 * the documents are issued as usual, and a download renders its PDF then,
 * once, as when the `pdf` queue has not got to it yet. dompdf takes a
 * second or more a document; the pages here do not need it.
 */
function holdPortalPdfs(): void
{
    Queue::fake([RenderInvoice::class, RenderReceipt::class, RenderLeaseContract::class]);
}

/** The lease's (only) bill. */
function portalBillOf(Organization $organization, Lease $lease): Bill
{
    return inOrganization($organization, fn (): Bill => Bill::query()->where('lease_id', $lease->id)->sole());
}

/** A portal page as the user, in a fresh request. */
function portalPage(User $user, string $path = ''): TestResponse
{
    billsFreshRequest();

    return test()->actingAs($user)->get('/portal'.$path);
}

/** The ids a rendered portal page lists for data-$attribute (bill, lease, organization). */
function portalIds(TestResponse $page, string $attribute): array
{
    preg_match_all('/data-'.$attribute.'="(\d+)"/', (string) $page->getContent(), $matches);

    return array_values(array_unique(array_map('intval', $matches[1])));
}

/** Kemi also rents Suite 2 at Bello Homes' Lagoon View, on her same account. */
function kemiAlsoAtBello(): Lease
{
    $profile = tenantIn(test()->other, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com', 'user_id' => test()->kemi->id]);
    $lease = activeLease(test()->other, test()->otherOwner, unitIn(test()->other, buildingIn(test()->other, ['name' => 'Lagoon View', 'slug' => 'lagoon-view']), ['unit_label' => 'Suite 2']), $profile, ['rent' => '900,000']);
    runBillGeneration();

    return $lease;
}
