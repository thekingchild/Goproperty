<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Support\Money;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\HtmlString;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Bills\BillService;
use Modules\Billing\Contracts\PayableBill;
use Modules\Billing\Contracts\PaymentOption;
use Modules\Billing\Contracts\PaymentOptions;
use Modules\Billing\Models\Bill;
use Modules\Leasing\Models\Lease;
use Modules\Tenants\Models\TenantProfile;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../../../../Billing/tests/Feature/invoice-helpers.php';

/*
 * P03-11a: the tenant portal — my units and leases, my bills with each
 * agency's branding, a bill's page with how to pay (ARCHITECTURE.md § 3–4,
 * UX_DESIGN.md § 1–2, ONBOARDING_COMPLIANCE.md § 1). Every page reads
 * through the "linked to my user" path (Modules\Tenants\Portal\
 * PortalTenancies) and TenantProfilePolicy::viewAsTenant; another tenant's
 * or another organization's bill, lease or invoice answers 404. Pages are
 * requested over HTTP as the tenant's browser does, each as a fresh request.
 *
 * Fixed data (orchestrator brief): every person and building that must NOT
 * show has its own fixed name, so a random value never matches by chance.
 */

beforeEach(function () {
    holdInvoicePdfs();
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
});

afterEach(fn () => billsFreshRequest());

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

describe('acceptance: a tenant sees only their own leases and bills', function () {
    it('shows the tenant their home, lease and bill, and nothing of the neighbour\'s', function () {
        $home = portalPage($this->kemi)->assertOk()
            ->assertSee('My rentals')
            ->assertSee('Adeyemi Realty')
            ->assertSee('Flat 3B · Palm Court')
            ->assertSee('22 Fola Osibo Road, Lekki Phase 1, Lagos')
            ->assertSee('₦1,500,000')
            ->assertSee('To pay now')
            ->assertDontSee('Flat 4C')
            ->assertDontSee('Tunde')
            ->assertDontSee('Foreign Court')
            ->assertDontSee('Bello Homes');

        expect(portalIds($home, 'lease'))->toBe([$this->lease->id])
            ->and(portalIds($home, 'bill'))->toBe([$this->bill->id])
            ->and(portalIds($home, 'organization'))->toBe([$this->acme->id]);

        $bills = portalPage($this->kemi, '/bills')->assertOk()->assertSee($this->bill->number)->assertDontSee($this->neighbourBill->number)->assertDontSee($this->foreignBill->number);
        expect(portalIds($bills, 'bill'))->toBe([$this->bill->id]);

        // The neighbour sees his own, never hers.
        $his = portalPage($this->tunde)->assertOk()->assertSee('Flat 4C · Palm Court')->assertDontSee('Flat 3B')->assertDontSee('Kemi');
        expect(portalIds($his, 'bill'))->toBe([$this->neighbourBill->id])
            ->and(portalIds($his, 'lease'))->toBe([$this->neighbourLease->id]);
    });

    it('shows a bill with how to pay it: the agency\'s branding as issued, bank account, reference and the pay page', function () {
        portalPage($this->kemi, "/bills/{$this->bill->id}")->assertOk()
            ->assertSee('Bill '.$this->bill->number)
            ->assertSee('Adeyemi Realty')
            ->assertSee('Amount to pay')
            ->assertSee('₦1,500,000')
            ->assertSee('Due 01/01/2027')
            ->assertSee('Pay by bank transfer')
            ->assertSee('0123456789')
            ->assertSee('Adeyemi Realty Ltd')
            ->assertSee($this->bill->payment_reference)
            ->assertSee('Put '.$this->bill->payment_reference.' in your transfer narration', false)
            ->assertSee(route('pay.show', $this->bill->payment_reference), false)
            ->assertSee(route('portal.bills.invoice', $this->bill->id), false)
            ->assertSee('border-top: 6px solid #0e6b5c', false)
            ->assertDontSee('0987654321');

        // The invoice web page of her own bill.
        portalPage($this->kemi, "/bills/{$this->bill->id}/invoice")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee($this->bill->number)
            ->assertSee('Kemi Adebayo');
    });

    it('shows a lease with its terms and its bills', function () {
        $page = portalPage($this->kemi, "/leases/{$this->lease->id}")->assertOk()
            ->assertSee('Flat 3B · Palm Court')
            ->assertSee(storedLease($this->acme, $this->lease)->contract_number)
            ->assertSee('01/01/2027 – 31/12/2027')
            ->assertSee('Yearly')
            ->assertSee('Active');

        expect(portalIds($page, 'bill'))->toBe([$this->bill->id]);
    });

    it('keeps the branding each bill was issued with, while the agency\'s frame follows its branding now', function () {
        invoiceBranding($this->acme, $this->owner, ['tradingName' => 'Adeyemi Prime Homes', 'primaryColor' => '#1D4ED8']);

        portalPage($this->kemi, "/bills/{$this->bill->id}")->assertOk()
            ->assertSee('border-top: 6px solid #0e6b5c', false)
            ->assertSee('Adeyemi Realty')
            ->assertDontSee('Adeyemi Prime Homes');

        portalPage($this->kemi)->assertOk()->assertSee('Adeyemi Prime Homes');
    });

    it('shows the online payment options of the bill and none once it is paid or void', function () {
        app(PaymentOptions::class)->register(new class implements PaymentOption
        {
            public function offers(PayableBill $bill): bool
            {
                return $bill->outstanding->isPositive();
            }

            public function render(PayableBill $bill): Htmlable
            {
                return new HtmlString('<a data-test="fake-pay-online" href="/pay-online/'.e($bill->paymentReference).'">Pay '.e($bill->outstanding->format()).' online</a>');
            }
        });

        portalPage($this->kemi, "/bills/{$this->bill->id}")->assertOk()
            ->assertSee('Pay online')
            ->assertSee('data-test="fake-pay-online"', false)
            ->assertSee('Pay ₦1,500,000 online');

        inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, storedBill($this->acme, $this->bill), 'Issued to the wrong flat'));

        portalPage($this->kemi, "/bills/{$this->bill->id}")->assertOk()
            ->assertSee('This bill is cancelled: do not pay it')
            ->assertSee('Issued to the wrong flat')
            ->assertDontSee('fake-pay-online')
            ->assertDontSee('Pay by bank transfer')
            ->assertDontSee('0123456789');

        portalPage($this->kemi)->assertOk()->assertSee('Nothing to pay right now.')->assertDontSee('To pay now');
    });

    it('leaves out draft leases, and shows a member who rents nothing an empty state', function () {
        $draft = draftLease($this->acme, $this->owner, unitIn($this->acme, buildingIn($this->acme, ['name' => 'Draft House', 'slug' => 'draft-house']), ['unit_label' => 'Room 1']), $this->kemiAtAcme);

        portalPage($this->kemi)->assertOk()->assertDontSee('Draft House');
        portalPage($this->kemi, "/leases/{$draft->id}")->assertNotFound();

        portalPage($this->owner)->assertOk()->assertSee('No rentals yet')->assertDontSee('Palm Court');
        portalPage($this->owner, '/bills')->assertOk()->assertSee('No bills yet');
        portalPage($this->owner, "/bills/{$this->bill->id}")->assertNotFound();
    });

    it('stops showing anything once the agency archives the tenant\'s record, or unlinks the account', function () {
        inOrganization($this->acme, fn () => TenantProfile::query()->whereKey($this->kemiAtAcme->id)->firstOrFail()->delete());

        portalPage($this->kemi)->assertOk()->assertSee('No rentals yet');
        portalPage($this->kemi, "/bills/{$this->bill->id}")->assertNotFound();
        portalPage($this->kemi, "/leases/{$this->lease->id}")->assertNotFound();
        portalPage($this->kemi, "/bills/{$this->bill->id}/invoice")->assertNotFound();
    });
});

describe('sub: a tenant of two organizations', function () {
    it('sees both agencies, each with its own leases and bills, and nothing else', function () {
        $bello = kemiAlsoAtBello();
        $belloBill = portalBillOf($this->other, $bello);

        $home = portalPage($this->kemi)->assertOk()
            ->assertSee('Adeyemi Realty')
            ->assertSee('Bello Homes')
            ->assertSee('Flat 3B · Palm Court')
            ->assertSee('Suite 2 · Lagoon View')
            ->assertDontSee('Foreign Court')
            ->assertDontSee('Flat 4C');

        expect(portalIds($home, 'organization'))->toEqualCanonicalizing([$this->acme->id, $this->other->id])
            ->and(portalIds($home, 'lease'))->toEqualCanonicalizing([$this->lease->id, $bello->id])
            ->and(portalIds($home, 'bill'))->toEqualCanonicalizing([$this->bill->id, $belloBill->id]);

        $bills = portalPage($this->kemi, '/bills')->assertOk();
        expect(portalIds($bills, 'bill'))->toEqualCanonicalizing([$this->bill->id, $belloBill->id]);

        // Each bill with its own agency's branding and bank account.
        portalPage($this->kemi, "/bills/{$belloBill->id}")->assertOk()
            ->assertSee('Bello Homes')
            ->assertSee('border-top: 6px solid #7a2e8c', false)
            ->assertSee('0987654321')
            ->assertDontSee('0123456789');
        portalPage($this->kemi, "/leases/{$bello->id}")->assertOk()->assertSee('Suite 2 · Lagoon View');
        portalPage($this->kemi, "/bills/{$belloBill->id}/invoice")->assertOk()->assertSee($belloBill->number);

        // Still a tenant, never a member of either.
        billsFreshRequest();
        $this->actingAs($this->kemi)->get('/o/acme-homes')->assertNotFound();
        billsFreshRequest();
        $this->actingAs($this->kemi)->get('/o/other-realty')->assertNotFound();
    });
});

describe('sub: ID guessing returns 404', function () {
    it('answers 404 for another tenant\'s or another organization\'s bill, lease and invoice, like a missing one', function (string $path) {
        $ids = [
            'neighbourBill' => $this->neighbourBill->id,
            'foreignBill' => $this->foreignBill->id,
            'neighbourLease' => $this->neighbourLease->id,
            'foreignLease' => $this->foreignLease->id,
            'missing' => 999999,
        ];

        portalPage($this->kemi, strtr($path, array_map(strval(...), array_combine(array_map(fn ($key) => '{'.$key.'}', array_keys($ids)), $ids))))
            ->assertNotFound()
            ->assertDontSee('Tunde')
            ->assertDontSee('Foreign');
    })->with([
        'neighbour\'s bill' => ['/bills/{neighbourBill}'],
        'neighbour\'s invoice' => ['/bills/{neighbourBill}/invoice'],
        'neighbour\'s lease' => ['/leases/{neighbourLease}'],
        'other organization\'s bill' => ['/bills/{foreignBill}'],
        'other organization\'s invoice' => ['/bills/{foreignBill}/invoice'],
        'other organization\'s lease' => ['/leases/{foreignLease}'],
        'missing bill' => ['/bills/{missing}'],
        'missing invoice' => ['/bills/{missing}/invoice'],
        'missing lease' => ['/leases/{missing}'],
        'a bill id given as a lease' => ['/leases/{foreignBill}'],
    ]);

    it('refuses a bill page whose locked id is changed to someone else\'s bill', function () {
        $snapshot = LivewireHttp::snapshot(portalPage($this->kemi, "/bills/{$this->bill->id}")->assertOk());
        $forged = json_decode($snapshot, true);
        $forged['data']['billId'] = $this->neighbourBill->id;

        billsFreshRequest();
        config(['app.debug' => false]);
        $response = LivewireHttp::update(test()->actingAs($this->kemi), (string) json_encode($forged));

        $response->assertStatus(419);
        expect((string) $response->getContent())->not->toContain($this->neighbourBill->number);

        // Changing it as an update is refused too (the id is locked).
        billsFreshRequest();
        $this->actingAs($this->kemi);
        $refused = LivewireHttp::update(test(), $snapshot, ['billId' => $this->neighbourBill->id]);
        expect($refused->status())->not->toBe(200)
            ->and((string) $refused->getContent())->not->toContain($this->neighbourBill->number);
    });

    it('sends guests to sign in', function (string $path) {
        billsFreshRequest();
        $this->get('/portal'.$path)->assertRedirect('/login');
    })->with(['', '/bills', '/bills/1', '/bills/1/invoice', '/leases/1']);
});

describe('sub: suspended organization', function () {
    it('lets the tenant still view everything, read-only, with how to pay', function (OrganizationStatus $status) {
        $this->acme->forceFill(['status' => $status])->save();

        portalPage($this->kemi)->assertOk()
            ->assertSee('Read-only for now')
            ->assertSee('Flat 3B · Palm Court')
            ->assertSee($this->bill->number);
        portalPage($this->kemi, '/bills')->assertOk()->assertSee($this->bill->number);
        portalPage($this->kemi, "/bills/{$this->bill->id}")->assertOk()
            ->assertSee('Read-only for now')
            ->assertSee('0123456789')
            ->assertSee($this->bill->payment_reference);
        portalPage($this->kemi, "/leases/{$this->lease->id}")->assertOk()->assertSee('Read-only for now');
        portalPage($this->kemi, "/bills/{$this->bill->id}/invoice")->assertOk()->assertSee($this->bill->number);

        // Another agency's tenant sees no notice.
        $bello = kemiAlsoAtBello();
        portalPage($this->kemi, '/bills/'.portalBillOf($this->other, $bello)->id)->assertOk()->assertDontSee('Read-only for now');
    })->with([
        'suspended' => [OrganizationStatus::Suspended],
        'cancelled' => [OrganizationStatus::Cancelled],
    ]);
});

describe('sub: impersonation', function () {
    beforeEach(function () {
        // Kemi also works for Acme as an agent; she rents from Bello Homes.
        joinAs($this->kemi, $this->acme, OrganizationRole::Agent);
        $this->bello = kemiAlsoAtBello();
        $this->belloBill = portalBillOf($this->other, $this->bello);
    });

    it('never shows staff impersonating a member of one organization her tenancy at another', function (ImpersonationMode $mode) {
        enterSupport(startSupport(staffMember(), $this->kemi, $this->acme, $mode))->assertRedirect();

        asSupport()->get('/portal')->assertOk()->assertDontSee('Bello Homes')->assertDontSee('Lagoon View');
        asSupport()->get('/portal/bills')->assertOk()->assertDontSee($this->belloBill->number);
        asSupport()->get("/portal/bills/{$this->belloBill->id}")->assertNotFound();
        asSupport()->get("/portal/leases/{$this->bello->id}")->assertNotFound();
        asSupport()->get("/portal/bills/{$this->belloBill->id}/invoice")->assertForbidden();

        // Her tenancy at the session's organization is the session's to see; its invoice (contact details) is not.
        asSupport()->get("/portal/bills/{$this->bill->id}")->assertOk()->assertDontSee(route('portal.bills.invoice', $this->bill->id), false);
        asSupport()->get("/portal/bills/{$this->bill->id}/invoice")->assertForbidden();

        // Signed in herself, she sees both.
        billsFreshRequest();
        $this->flushSession();
        portalPage($this->kemi)->assertOk()->assertSee('Bello Homes')->assertSee('Adeyemi Realty');
    })->with([
        'read-only' => [ImpersonationMode::ReadOnly],
        'write' => [ImpersonationMode::Write],
    ]);
});

describe('a member who also rents', function () {
    it('sees the workspace in its place and the rentals in the portal, never mixed', function () {
        joinAs($this->kemi, $this->acme, OrganizationRole::Agent);
        $bello = kemiAlsoAtBello();

        // Signing in lands on her workspace (ONBOARDING_COMPLIANCE.md § 1 "After login": mixed → last used).
        billsFreshRequest();
        $this->post('/login', ['identifier' => 'kemi.adebayo@example.com', 'password' => 'password'])->assertRedirect('/o/acme-homes');

        // The workspace shows Acme's bills (as an agent), the portal only her own rentals.
        billsFreshRequest();
        $this->actingAs($this->kemi)->get('/o/acme-homes/bills')->assertOk()->assertSee($this->neighbourBill->number);
        $portal = portalPage($this->kemi)->assertOk()->assertSee('My rentals')->assertSee(route('workspace.home', 'acme-homes'), false);
        expect(portalIds($portal, 'bill'))->toEqualCanonicalizing([$this->bill->id, portalBillOf($this->other, $bello)->id])
            ->and((string) $portal->getContent())->not->toContain($this->neighbourBill->number);

        // Bello's workspace stays closed to her.
        billsFreshRequest();
        $this->actingAs($this->kemi)->get('/o/other-realty')->assertNotFound();
    });
});

describe('portal pages at 360 px', function () {
    it('lays every portal page out for phones: no tables, no fixed widths, wrapping text and big targets', function () {
        $pages = [
            portalPage($this->kemi)->assertOk(),
            portalPage($this->kemi, '/bills')->assertOk(),
            portalPage($this->kemi, "/bills/{$this->bill->id}")->assertOk(),
            portalPage($this->kemi, "/leases/{$this->lease->id}")->assertOk(),
        ];

        foreach ($pages as $page) {
            $html = (string) $page->getContent();

            expect($html)->toContain('name="viewport" content="width=device-width')
                ->toContain('data-test="bottom-nav"')
                ->toContain('data-test="portal-nav"')
                ->not->toContain('<table')
                ->not->toMatch('/\b(min-)?w-\[(3[6-9]\d|[4-9]\d\d|\d{4,})px\]/');
        }

        // Every portal view: tab targets of 44 px, long numbers and names allowed to wrap.
        $views = collect([
            ...File::allFiles(base_path('Modules/Tenants/resources/views/livewire/portal')),
            ...File::allFiles(base_path('Modules/Tenants/resources/views/portal/partials')),
        ]);
        $source = $views->map(fn (SplFileInfo $file): string => (string) file_get_contents($file->getPathname()))->implode("\n");

        expect($source)->toContain('min-h-11')
            ->toContain('break-words')
            ->toContain('break-all')
            ->not->toContain('<table');
    });

    it('shows the sign-in message once on the portal home', function () {
        billsFreshRequest();
        $this->actingAs($this->kemi)->withSession(['status' => 'You are signed in.'])->get('/portal')
            ->assertOk()
            ->assertSee('You are signed in.');
    });
});

it('opens for a phone-only tenant account', function () {
    $phoneOnly = User::factory()->create(['name' => 'Ngozi Eze', 'email' => null, 'email_verified_at' => null, 'phone' => '+2348036667777']);
    $profile = tenantIn($this->acme, ['first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '+2348036667777', 'email' => null, 'user_id' => $phoneOnly->id]);
    $lease = activeLease($this->acme, $this->owner, unitIn($this->acme, buildingIn($this->acme, ['name' => 'Cedar Place', 'slug' => 'cedar-place']), ['unit_label' => 'Flat 1']), $profile, ['rent' => '600,000']);
    runBillGeneration();

    $page = portalPage($phoneOnly)->assertOk()->assertSee('Flat 1 · Cedar Place')->assertSee(Money::naira(600_000)->format());
    expect(portalIds($page, 'bill'))->toBe([portalBillOf($this->acme, $lease)->id]);
});

it('opens Settings → Profile (the "Account" tab) for a phone-only tenant account', function () {
    $phoneOnly = User::factory()->create(['name' => 'Ngozi Eze', 'email' => null, 'email_verified_at' => null, 'phone' => '+2348036667777']);

    billsFreshRequest();
    $this->actingAs($phoneOnly)->get('/settings/profile')->assertOk()->assertSee('Ngozi Eze');
});
