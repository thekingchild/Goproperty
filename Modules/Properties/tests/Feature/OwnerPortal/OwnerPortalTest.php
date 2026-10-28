<?php

declare(strict_types=1);

use App\Branding\BrandColours;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Modules\Properties\Livewire\OwnerPortal\OwnerBillShow;
use Modules\Properties\Livewire\OwnerPortal\OwnerBuildingShow;
use Modules\Properties\Livewire\OwnerPortal\OwnerHome;
use Modules\Properties\Livewire\OwnerPortal\OwnerLeaseShow;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\OwnerPortal\ReadOnlyOwnerPage;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/owner-world.php';
require_once __DIR__.'/../../../../../tests/Support/impersonation.php';

/*
 * P03-15 acceptance: the owner portal (/owner). An owner sees only the
 * buildings linked to their owner profiles, across organizations; another
 * owner's building (or a lease or bill of it) is 404; nothing is editable;
 * `property-owner` never opens /o/{slug}. Every page is read over HTTP as
 * the owner's browser gets it, each request fresh.
 */

beforeEach(fn () => ownerWorld());

afterEach(fn () => billsFreshRequest());

describe('acceptance: an owner sees only buildings linked to their owner profiles, across two organizations', function () {
    it('lists her buildings at both organizations, each under that organization\'s branding, and nothing else', function () {
        $home = ownerPortalPage($this->ngozi)->assertOk();

        expect(ownerPortalIds($home, 'building'))->toEqual([min($this->palm->id, $this->lagoon->id), max($this->palm->id, $this->lagoon->id)])
            ->and(ownerPortalIds($home, 'organization'))->toEqual([$this->acme->id, $this->other->id]);
        $home->assertSeeInOrder(['Adeyemi Realty', 'Palm Court', '22 Fola Osibo Road', 'Bello Homes', 'Lagoon View'])
            ->assertSee('2 units · 1 occupied, 1 vacant')
            ->assertSee(BrandColours::textShade('#0E6B5C'), false)
            ->assertSee(BrandColours::textShade('#7A2E8C'), false)
            ->assertDontSee('Cedar House')->assertDontSee('Office Block')->assertDontSee('Harbour Point')
            ->assertDontSee('Kemi Adebayo');
    });

    it('shows a building with its units, occupancy, active lease (the tenant\'s name only) and bills', function () {
        $page = ownerPortalPage($this->ngozi, "/buildings/{$this->palm->id}")->assertOk();

        expect(ownerPortalIds($page, 'unit'))->toEqual([$this->flat3b->id, $this->flat4c->id])
            ->and(ownerPortalIds($page, 'lease'))->toEqual([$this->lease->id])
            ->and(ownerPortalIds($page, 'bill'))->toEqual([$this->bill->id]);
        $page->assertSee('Palm Court')->assertSee('Adeyemi Realty')
            ->assertSee('Flat 3B')->assertSee('Occupied')->assertSee('Flat 4C')->assertSee('Vacant')
            ->assertSee('Let to Kemi Adebayo')->assertSee('₦1,500,000')
            ->assertSee($this->bill->number)->assertSee('Unpaid')
            // Minimal tenant detail: never their phone number or email address.
            ->assertDontSee('+2348031112222')->assertDontSee('0803 111 2222')->assertDontSee('kemi.adebayo@example.com')
            ->assertDontSee('Tunde Bakare')->assertDontSee($this->cedarBill->number);
    });

    it('shows a lease and a bill of her building, with the tenant\'s name only and no way to pay or change anything', function () {
        ownerPortalPage($this->ngozi, "/leases/{$this->lease->id}")->assertOk()
            ->assertSee('Flat 3B · Palm Court')->assertSee('Kemi Adebayo')->assertSee('Active')
            ->assertSee('01/01/2027 – 31/12/2027')->assertSee('₦1,500,000')->assertSee($this->bill->number)
            ->assertDontSee('kemi.adebayo@example.com')->assertDontSee('+2348031112222');

        $bill = ownerPortalPage($this->ngozi, "/bills/{$this->bill->id}")->assertOk()
            ->assertSee('Bill '.$this->bill->number)->assertSee('Kemi Adebayo')->assertSee('Unpaid')
            ->assertSee('₦1,500,000')->assertSee('Still owed')
            ->assertDontSee('kemi.adebayo@example.com')->assertDontSee('+2348031112222')
            ->assertDontSee($this->bill->payment_reference)->assertDontSee('0123456789');
        expect((string) $bill->getContent())->not->toContain('/pay/')->not->toContain('invoice')->not->toContain('wire:click');

        ownerPortalPage($this->ngozi, "/bills/{$this->belloBill->id}")->assertOk()->assertSee('Musa Danjuma')->assertSee('Bello Homes');
    });

    it('shows the other owner only his own building', function () {
        $home = ownerPortalPage($this->femi)->assertOk();

        expect(ownerPortalIds($home, 'building'))->toEqual([$this->cedar->id])
            ->and(ownerPortalIds($home, 'organization'))->toEqual([$this->acme->id]);
        $home->assertDontSee('Palm Court')->assertDontSee('Lagoon View');
    });

    it('stops showing an organization\'s buildings once the owner record is archived or the access removed', function () {
        inOrganization($this->other, fn () => OwnerProfile::query()->findOrFail($this->ngoziAtBello->id)->delete());

        expect(ownerPortalIds(ownerPortalPage($this->ngozi)->assertOk(), 'building'))->toEqual([$this->palm->id]);
        ownerPortalPage($this->ngozi, "/buildings/{$this->lagoon->id}")->assertNotFound();
        ownerPortalPage($this->ngozi, "/bills/{$this->belloBill->id}")->assertNotFound();

        // The role alone (without the link) shows nothing, and the link alone (without the role) neither.
        inOrganization($this->acme, fn () => $this->ngozi->removeRole(OrganizationRole::PropertyOwner->value));
        ownerPortalPage($this->ngozi)->assertOk()->assertSee('No properties yet');
        ownerPortalPage($this->ngozi, "/buildings/{$this->palm->id}")->assertNotFound();
    });

    it('keeps an archived building visible, marked, and shows a suspended organization\'s buildings read-only', function () {
        inOrganization($this->acme, fn () => $this->palm->forceFill(['status' => 'archived'])->save());
        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

        ownerPortalPage($this->ngozi)->assertOk()->assertSee('Palm Court')->assertSee('No longer managed');
        ownerPortalPage($this->ngozi, "/buildings/{$this->palm->id}")->assertOk()->assertSee('no longer manages this building');
        ownerPortalPage($this->ngozi, "/bills/{$this->bill->id}")->assertOk();
    });
});

describe('acceptance: another owner\'s building (or a lease or bill of it) is 404', function () {
    it('answers 404 for anything that is not one of her buildings, like a missing record', function (string $path) {
        $path = strtr($path, [
            '{cedar}' => (string) $this->cedar->id,
            '{cedarLease}' => (string) $this->cedarLease->id,
            '{cedarBill}' => (string) $this->cedarBill->id,
            '{office}' => (string) $this->office->id,
            '{harbour}' => (string) $this->harbour->id,
            '{harbourLease}' => (string) $this->harbourLease->id,
            '{harbourBill}' => (string) $this->harbourBill->id,
            '{billAsLease}' => (string) $this->bill->id,
        ]);

        ownerPortalPage($this->ngozi, $path)->assertNotFound()
            ->assertDontSee('Cedar House')->assertDontSee('Harbour Point')->assertDontSee('Tunde Bakare')->assertDontSee('Foreign Tenant');
    })->with([
        'another owner\'s building' => '/buildings/{cedar}',
        'a lease of another owner\'s building' => '/leases/{cedarLease}',
        'a bill of another owner\'s building' => '/bills/{cedarBill}',
        'the organization\'s own building' => '/buildings/{office}',
        'another organization\'s building' => '/buildings/{harbour}',
        'another organization\'s lease' => '/leases/{harbourLease}',
        'another organization\'s bill' => '/bills/{harbourBill}',
        'a missing building' => '/buildings/999999',
        'a missing lease' => '/leases/999999',
        'a missing bill' => '/bills/999999',
    ]);

    it('never shows a draft lease, even of her own building', function () {
        $draft = draftLease($this->acme, $this->owner, $this->flat4c, tenantIn($this->acme, ['first_name' => 'Draft', 'last_name' => 'Person', 'phone' => '+2348030001111']));

        ownerPortalPage($this->ngozi, "/leases/{$draft->id}")->assertNotFound();
        ownerPortalPage($this->ngozi, "/buildings/{$this->palm->id}")->assertOk()->assertDontSee('Draft Person');
    });

    it('answers 404 to the owner of the other building too, and sends guests to sign in', function () {
        ownerPortalPage($this->femi, "/buildings/{$this->palm->id}")->assertNotFound();
        ownerPortalPage($this->femi, "/bills/{$this->bill->id}")->assertNotFound();
        ownerPortalPage($this->femi, "/leases/{$this->lease->id}")->assertNotFound();

        foreach (['', "/buildings/{$this->palm->id}", "/leases/{$this->lease->id}", "/bills/{$this->bill->id}"] as $path) {
            billsFreshRequest();
            $this->flushSession();
            $this->get('/owner'.$path)->assertRedirect('/login');
        }
    });

    it('refuses a page whose locked id is changed to another owner\'s record', function () {
        config(['app.debug' => false]);
        $snapshot = LivewireHttp::snapshot(ownerPortalPage($this->ngozi, "/buildings/{$this->palm->id}")->assertOk());
        $data = json_decode($snapshot, true);
        $data['data']['buildingId'] = $this->cedar->id;

        billsFreshRequest();
        $this->actingAs($this->ngozi);
        $response = LivewireHttp::update($this, (string) json_encode($data), [], [['$refresh']]);

        expect($response->status())->toBe(419)
            ->and((string) $response->getContent())->not->toContain('Cedar House');

        billsFreshRequest();
        $this->actingAs($this->ngozi);
        $locked = LivewireHttp::update($this, $snapshot, ['buildingId' => $this->cedar->id]);
        expect($locked->status())->not->toBe(200)
            ->and((string) $locked->getContent())->not->toContain('Cedar House');
    });
});

describe('acceptance: nothing is editable', function () {
    it('has no write routes: every /owner page answers GET only, apart from accepting an invitation', function () {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'owner'));

        expect($routes)->not->toBeEmpty();

        foreach ($routes as $route) {
            $writes = array_diff($route->methods(), ['GET', 'HEAD']);
            expect($writes === [] || $route->getName() === 'owner.invitations.accept')->toBeTrue($route->uri());
        }

        foreach ([OwnerHome::class, OwnerBuildingShow::class, OwnerLeaseShow::class, OwnerBillShow::class] as $component) {
            $actions = array_values(array_filter(
                (new ReflectionClass($component))->getMethods(ReflectionMethod::IS_PUBLIC),
                fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $component && ! in_array($method->getName(), ['mount', 'render'], true),
            ));

            expect($actions)->toBe([], $component)
                ->and(is_subclass_of($component, ReadOnlyOwnerPage::class))->toBeTrue($component)
                ->and(is_subclass_of($component, Component::class))->toBeTrue();
        }
    });

    it('refuses every Livewire method call on the owner portal\'s pages, and changes nothing', function (string $path, string $method, array $params) {
        $path = strtr($path, ['{palm}' => (string) $this->palm->id, '{lease}' => (string) $this->lease->id, '{bill}' => (string) $this->bill->id]);
        $before = billsOf($this->acme)->map->only(['id', 'status', 'amount_kobo', 'paid_kobo'])->all();

        ownerPortalCall($this->ngozi, $path, $method, $params)->assertForbidden();

        expect(billsOf($this->acme)->map->only(['id', 'status', 'amount_kobo', 'paid_kobo'])->all())->toEqual($before);
    })->with([
        'home: save' => ['', 'save', []],
        'building: archive' => ['/buildings/{palm}', 'archive', []],
        'building: mount again' => ['/buildings/{palm}', 'mount', [999]],
        'building: $set' => ['/buildings/{palm}', '$set', ['buildingId', 1]],
        'lease: terminate' => ['/leases/{lease}', 'terminate', []],
        'bill: void' => ['/bills/{bill}', 'void', ['reason']],
        'bill: render' => ['/bills/{bill}', 'render', []],
    ]);

    it('still refreshes a page', function () {
        $response = ownerPortalCall($this->ngozi, "/buildings/{$this->palm->id}", '$refresh')->assertOk();

        expect(LivewireHttp::html($response))->toContain('Palm Court');
    });
});

describe('sub: property-owner never opens /o/{slug}', function () {
    it('answers 404 in either organization\'s workspace and lists no workspace for her', function () {
        foreach (['/o/acme-homes', '/o/acme-homes/owners', '/o/acme-homes/buildings', "/o/acme-homes/buildings/{$this->palm->slug}", '/o/acme-homes/bills', '/o/other-realty', '/o/other-realty/leases'] as $path) {
            billsFreshRequest();
            $this->actingAs($this->ngozi)->get($path)->assertNotFound();
        }

        billsFreshRequest();
        $this->actingAs($this->ngozi)->post('/organizations/acme-homes/switch')->assertNotFound();

        expect($this->ngozi->workspaceOrganizationIds())->toBe([])
            ->and($this->ngozi->roleNamesIn($this->acme))->toBe([OrganizationRole::PropertyOwner->value])
            ->and($this->ngozi->roleNamesIn($this->other))->toBe([OrganizationRole::PropertyOwner->value]);
    });

    it('lands an owner on /owner after signing in, and sends her there from the picker', function () {
        $this->flushSession();
        $this->post('/login', ['identifier' => 'ngozi.eze@example.com', 'password' => 'password'])->assertRedirect('/owner');

        billsFreshRequest();
        $this->actingAs($this->ngozi)->get('/dashboard')->assertRedirect('/owner');
    });

    it('shows a tenant who also owns buildings both portals, and a member her workspace first with the portal a click away', function () {
        // Kemi rents at Adeyemi and owns a building Bello manages.
        $kemi = User::factory()->create(['name' => 'Kemi Adebayo', 'email' => 'kemi.adebayo@example.com', 'phone' => '+2348031112222']);
        inOrganization($this->acme, fn () => $this->kemi->forceFill(['user_id' => $kemi->id])->save());
        $kemiOwner = ownerIn($this->other, ['type' => 'person', 'first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222']);
        linkOwner($this->other, $kemiOwner, $kemi);
        buildingIn($this->other, ['name' => 'Kemi Terraces', 'slug' => 'kemi-terraces', 'owner_profile_id' => $kemiOwner->id]);

        billsFreshRequest();
        $this->actingAs($kemi)->get('/dashboard')->assertOk()
            ->assertSee('data-test="portal-picker"', false)
            ->assertSee(route('portal.home'), false)
            ->assertSee(route('owner.home'), false);
        ownerPortalPage($kemi)->assertOk()->assertSee('Kemi Terraces')->assertDontSee('Palm Court');
        billsFreshRequest();
        $this->actingAs($kemi)->get('/portal')->assertOk()->assertSee('Flat 3B');

        // Ada works at Adeyemi and owns a building Bello manages: her workspace first; /owner works.
        $adaOwner = ownerIn($this->other, ['type' => 'person', 'first_name' => 'Ada', 'last_name' => 'Owner', 'email' => 'ada.owner@example.com']);
        linkOwner($this->other, $adaOwner, $this->owner);
        buildingIn($this->other, ['name' => 'Ada Gardens', 'slug' => 'ada-gardens', 'owner_profile_id' => $adaOwner->id]);
        billsFreshRequest();
        $this->flushSession();
        $this->post('/login', ['identifier' => 'ada.owner@example.com', 'password' => 'password'])->assertRedirect('/o/acme-homes');
        ownerPortalPage($this->owner)->assertOk()->assertSee('Ada Gardens')->assertDontSee('Palm Court');
        billsFreshRequest();
        $this->actingAs($this->owner)->get('/o/other-realty')->assertNotFound();
    });
});

describe('impersonation and scope', function () {
    it('shows staff impersonating the owner at one organization only that organization\'s buildings, in either mode', function (ImpersonationMode $mode) {
        $staff = staffMember();
        $handOff = $mode === ImpersonationMode::Write
            ? startSupport($staff, $this->ngozi, $this->acme, $mode)
            : startSupport($staff, $this->ngozi, $this->acme, $mode);
        enterSupport($handOff);

        freshRequest();
        $home = asSupport()->get('/owner')->assertOk();
        expect(ownerPortalIds($home, 'building'))->toEqual([$this->palm->id]);

        freshRequest();
        asSupport()->get("/owner/buildings/{$this->lagoon->id}")->assertNotFound();
        freshRequest();
        asSupport()->get("/owner/bills/{$this->bill->id}")->assertOk();
    })->with([ImpersonationMode::ReadOnly, ImpersonationMode::Write]);
});
