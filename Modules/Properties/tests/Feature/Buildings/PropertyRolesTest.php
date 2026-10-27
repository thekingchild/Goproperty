<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Gate;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Models\Property;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../../tests/Support/impersonation.php';

/*
 * P02-01b: who may see and change buildings (ARCHITECTURE.md § 5),
 * enforced by PropertyPolicy. Owners and administrators add, archive and
 * restore buildings; agents edit them; property owners and tenants never
 * open the workspace. A restricted organization adds none
 * (ONBOARDING_COMPLIANCE.md § 5.5); the central guards (suspension,
 * impersonation) apply on top.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->building = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->archived = buildingIn($this->acme, ['name' => 'Old Yard', 'slug' => 'old-yard', 'status' => PropertyStatus::Archived]);
});

afterEach(fn () => freshRequest());

/** @return array<string, bool> ability => allowed, as the policy answers inside the organization */
function buildingAbilitiesOf(User $user, Organization $organization, Property $building, Property $archived): array
{
    return app(OrganizationContext::class)->run($organization, fn (): array => [
        'viewAny' => Gate::forUser($user)->allows('viewAny', Property::class),
        'view' => Gate::forUser($user)->allows('view', $building),
        'viewArchived' => Gate::forUser($user)->allows('view', $archived),
        'create' => Gate::forUser($user)->allows('create', Property::class),
        'update' => Gate::forUser($user)->allows('update', $building),
        'addPhotos' => Gate::forUser($user)->allows('addPhotos', $building),
        'deletePhoto' => Gate::forUser($user)->allows('deletePhoto', $building),
        'delete' => Gate::forUser($user)->allows('delete', $building),
        'restore' => Gate::forUser($user)->allows('restore', $archived),
        'updateArchived' => Gate::forUser($user)->allows('update', $archived),
    ]);
}

it('enforces the ARCHITECTURE.md § 5 role rules in PropertyPolicy', function (OrganizationRole $role, array $expected) {
    $user = joinAs(User::factory()->create(), $this->acme, $role);

    expect(buildingAbilitiesOf($user, $this->acme, $this->building, $this->archived))->toBe($expected);
})->with([
    'org-owner' => [OrganizationRole::Owner, ['viewAny' => true, 'view' => true, 'viewArchived' => true, 'create' => true, 'update' => true, 'addPhotos' => true, 'deletePhoto' => true, 'delete' => true, 'restore' => true, 'updateArchived' => false]],
    'org-admin' => [OrganizationRole::Admin, ['viewAny' => true, 'view' => true, 'viewArchived' => true, 'create' => true, 'update' => true, 'addPhotos' => true, 'deletePhoto' => true, 'delete' => true, 'restore' => true, 'updateArchived' => false]],
    'agent' => [OrganizationRole::Agent, ['viewAny' => true, 'view' => true, 'viewArchived' => true, 'create' => false, 'update' => true, 'addPhotos' => true, 'deletePhoto' => true, 'delete' => false, 'restore' => false, 'updateArchived' => false]],
    'property-owner' => [OrganizationRole::PropertyOwner, ['viewAny' => false, 'view' => false, 'viewArchived' => false, 'create' => false, 'update' => false, 'addPhotos' => false, 'deletePhoto' => false, 'delete' => false, 'restore' => false, 'updateArchived' => false]],
    'tenant' => [OrganizationRole::Tenant, ['viewAny' => false, 'view' => false, 'viewArchived' => false, 'create' => false, 'update' => false, 'addPhotos' => false, 'deletePhoto' => false, 'delete' => false, 'restore' => false, 'updateArchived' => false]],
]);

it('grants the building permissions to the roles ARCHITECTURE.md § 5 names, and classes them for the central guards', function () {
    $holders = fn (OrganizationPermission $permission): array => array_values(array_map(
        fn (OrganizationRole $role): string => $role->value,
        array_filter(OrganizationRole::cases(), fn (OrganizationRole $role): bool => in_array($permission, $role->permissions(), true)),
    ));

    expect($holders(OrganizationPermission::ViewProperties))->toBe(['org-owner', 'org-admin', 'agent', 'property-owner'])
        ->and($holders(OrganizationPermission::ManageProperties))->toBe(['org-owner', 'org-admin', 'agent'])
        ->and($holders(OrganizationPermission::ManagePropertyPortfolio))->toBe(['org-owner', 'org-admin'])
        ->and(OrganizationPermission::ViewProperties->allowedWhileSuspended())->toBeTrue()
        ->and(OrganizationPermission::ManageProperties->allowedWhileSuspended())->toBeFalse()
        ->and(OrganizationPermission::ManagePropertyPortfolio->allowedWhileSuspended())->toBeFalse()
        ->and(OrganizationPermission::ManagePropertyPortfolio->blockedWhileImpersonating())->toBeFalse();
});

it('refuses everything to a member of another organization and to strangers', function () {
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $outsider = joinAs(User::factory()->create(), $other);

    foreach ([$outsider, User::factory()->create()] as $user) {
        expect(array_filter(buildingAbilitiesOf($user, $this->acme, $this->building, $this->archived)))->toBe([]);
    }

    expect(app(OrganizationContext::class)->run($other, fn () => Property::query()->find($this->building->id)))->toBeNull();
});

it('opens the pages only for the roles allowed, and 404 outside the workspace', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $landlord = joinAs(User::factory()->create(), $this->acme, OrganizationRole::PropertyOwner);
    $tenant = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Tenant);

    foreach ([$this->owner, $admin] as $user) {
        buildingsPage($user)->assertOk()->assertSee('Palm Court')->assertSee('data-test="add-building"', false)->assertSee('data-test="archive-'.$this->building->id.'"', false);
        buildingsPage($user, '/create')->assertOk();
        buildingsPage($user, '/palm-court')->assertOk()->assertSee('data-test="edit-building"', false)->assertSee('data-test="archive-building"', false);
        buildingsPage($user, '/palm-court/edit')->assertOk()->assertSee('Save building');
    }

    // Agents edit buildings, but neither add nor archive them.
    buildingsPage($agent)->assertOk()->assertSee('Palm Court')->assertDontSee('data-test="add-building"', false)->assertDontSee('data-test="archive-', false);
    buildingsPage($agent, '/palm-court')->assertOk()->assertSee('data-test="edit-building"', false)->assertDontSee('data-test="archive-building"', false);
    buildingsPage($agent, '/palm-court/edit')->assertOk();
    buildingsPage($agent, '/create')->assertForbidden();
    buildingsPage($agent, '?archived=1')->assertOk()->assertSee('Old Yard')->assertDontSee('data-test="restore-', false);

    foreach ([$landlord, $tenant, User::factory()->create()] as $user) {
        buildingsPage($user)->assertNotFound();
        buildingsPage($user, '/palm-court')->assertNotFound();
    }
});

it('refuses an agent\'s add, archive and restore sent straight to the server, but saves an agent\'s edit', function () {
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $ownerForm = buildingsSnapshot($this->owner, '/create');

    // A snapshot taken by the owner, replayed by the agent: the page refuses to save.
    $this->actingAs($agent);
    LivewireHttp::update($this, $ownerForm, buildingForm(['name' => 'Sneaky Court']), [['save']])->assertForbidden();

    $list = buildingsSnapshot($agent);
    LivewireHttp::update($this, $list, [], [['archive', [$this->building->id]]])->assertForbidden();
    LivewireHttp::update($this, $list, [], [['restore', [$this->archived->id]]])->assertForbidden();
    LivewireHttp::update($this, buildingsSnapshot($agent, '/palm-court'), [], [['archive']])->assertForbidden();

    LivewireHttp::update($this, buildingsSnapshot($agent, '/palm-court/edit'), ['landmark' => 'By the market'], [['save']])->assertOk();

    $fresh = buildingsOf($this->acme);
    expect($fresh->pluck('name')->all())->not->toContain('Sneaky Court')
        ->and($fresh->firstWhere('id', $this->building->id)->status)->toBe(PropertyStatus::Active)
        ->and($fresh->firstWhere('id', $this->building->id)->landmark)->toBe('By the market')
        ->and($fresh->firstWhere('id', $this->archived->id)->status)->toBe(PropertyStatus::Archived);
});

it('shows buildings read-only while the organization is suspended', function () {
    $form = buildingsSnapshot($this->owner, '/palm-court/edit');
    $show = buildingsSnapshot($this->owner, '/palm-court');
    $list = buildingsSnapshot($this->owner);
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    buildingsPage($this->owner)->assertOk()->assertSee('Palm Court')->assertDontSee('data-test="add-building"', false)->assertDontSee('data-test="archive-', false);
    buildingsPage($this->owner, '/palm-court')->assertOk()->assertDontSee('data-test="edit-building"', false)->assertDontSee('data-test="photo-input"', false);
    buildingsPage($this->owner, '/palm-court/edit')->assertForbidden();
    buildingsPage($this->owner, '/create')->assertForbidden();
    LivewireHttp::update($this, $form, ['name' => 'Changed'], [['save']])->assertForbidden();
    LivewireHttp::update($this, $show, [], [['archive']])->assertForbidden();
    LivewireHttp::update($this, $list, [], [['archive', [$this->building->id]]])->assertForbidden();
    LivewireHttp::update($this, $list, [], [['restore', [$this->archived->id]]])->assertForbidden();

    expect($this->building->fresh()->name)->toBe('Palm Court')
        ->and($this->building->fresh()->status)->toBe(PropertyStatus::Active)
        ->and($this->archived->fresh()->status)->toBe(PropertyStatus::Archived);
});

it('adds and restores no building while the organization is restricted, but keeps editing and archiving', function () {
    $this->acme->forceFill(['status' => OrganizationStatus::Restricted])->save();

    buildingsPage($this->owner)->assertOk()->assertDontSee('data-test="add-building"', false);
    buildingsPage($this->owner, '/create')->assertForbidden();
    buildingsPage($this->owner, '?archived=1')->assertOk()->assertDontSee('data-test="restore-', false);
    LivewireHttp::update($this, buildingsSnapshot($this->owner), [], [['restore', [$this->archived->id]]])->assertForbidden();

    LivewireHttp::update($this, buildingsSnapshot($this->owner, '/palm-court/edit'), ['landmark' => 'By the market'], [['save']])->assertOk();
    LivewireHttp::update($this, buildingsSnapshot($this->owner, '/palm-court'), [], [['archive']])->assertOk();

    expect($this->building->fresh()->landmark)->toBe('By the market')
        ->and($this->building->fresh()->status)->toBe(PropertyStatus::Archived)
        ->and($this->archived->fresh()->status)->toBe(PropertyStatus::Archived);
});

it('lets impersonating staff in write mode edit a building, audited with both identities, but never archive one', function () {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write))->assertRedirect('/o/acme-homes');

    $form = LivewireHttp::snapshot(asSupport()->get('/o/acme-homes/buildings/palm-court/edit')->assertOk());
    freshRequest();
    LivewireHttp::update(test(), $form, ['landmark' => 'Behind the church'], [['save']])->assertOk();

    $show = LivewireHttp::snapshot(asSupport()->get('/o/acme-homes/buildings/palm-court')->assertOk());
    freshRequest();
    LivewireHttp::update(test(), $show, [], [['archive']])->assertForbidden();

    $list = LivewireHttp::snapshot(asSupport()->get('/o/acme-homes/buildings')->assertOk());
    freshRequest();
    LivewireHttp::update(test(), $list, [], [['archive', [$this->building->id]]])->assertForbidden();

    $edit = AuditEntry::query()->where('log_name', 'properties')->where('event', 'property.updated')->sole();
    expect($this->building->fresh()->landmark)->toBe('Behind the church')
        ->and($this->building->fresh()->status)->toBe(PropertyStatus::Active)
        ->and($edit->impersonator_staff_id)->toBe($staff->id)
        ->and($edit->causer_id)->toBe($this->owner->id)
        ->and(AuditEntry::query()->where('event', 'property.archived')->exists())->toBeFalse();
});

it('lets impersonating staff in read-only mode open buildings but change nothing', function () {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme))->assertRedirect('/o/acme-homes');

    asSupport()->get('/o/acme-homes/buildings')->assertOk()->assertSee('Palm Court')->assertDontSee('data-test="add-building"', false);
    $show = LivewireHttp::snapshot(asSupport()->get('/o/acme-homes/buildings/palm-court')->assertOk()->assertDontSee('data-test="edit-building"', false));
    freshRequest();
    LivewireHttp::update(test(), $show, [], [['archive']])->assertForbidden();
    asSupport()->get('/o/acme-homes/buildings/palm-court/edit')->assertForbidden();

    expect($this->building->fresh()->status)->toBe(PropertyStatus::Active);
});
