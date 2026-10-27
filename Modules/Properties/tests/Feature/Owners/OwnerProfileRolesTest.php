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
use Modules\Properties\Models\OwnerProfile;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../../tests/Support/impersonation.php';

/*
 * P02-01a: who may see and change property owners (ARCHITECTURE.md § 5),
 * enforced by OwnerProfilePolicy. Owners and administrators keep the
 * records; agents look them up; property owners and tenants never open the
 * workspace. The central guards (suspension, impersonation) apply on top.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->record = ownerIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare']);
    $this->archived = ownerIn($this->acme, ['first_name' => 'Old', 'last_name' => 'Owner']);
    app(OrganizationContext::class)->run($this->acme, fn () => $this->archived->delete());
});

afterEach(fn () => freshRequest());

/** @return array<string, bool> ability => allowed, as the policy answers inside the organization */
function ownerAbilitiesOf(User $user, Organization $organization, OwnerProfile $record, OwnerProfile $archived): array
{
    return app(OrganizationContext::class)->run($organization, fn (): array => [
        'viewAny' => Gate::forUser($user)->allows('viewAny', OwnerProfile::class),
        'view' => Gate::forUser($user)->allows('view', $record),
        'create' => Gate::forUser($user)->allows('create', OwnerProfile::class),
        'update' => Gate::forUser($user)->allows('update', $record),
        'delete' => Gate::forUser($user)->allows('delete', $record),
        'restore' => Gate::forUser($user)->allows('restore', $archived),
    ]);
}

it('enforces the ARCHITECTURE.md § 5 role rules in OwnerProfilePolicy', function (OrganizationRole $role, array $expected) {
    $user = joinAs(User::factory()->create(), $this->acme, $role);

    expect(ownerAbilitiesOf($user, $this->acme, $this->record, $this->archived))->toBe($expected);
})->with([
    'org-owner' => [OrganizationRole::Owner, ['viewAny' => true, 'view' => true, 'create' => true, 'update' => true, 'delete' => true, 'restore' => true]],
    'org-admin' => [OrganizationRole::Admin, ['viewAny' => true, 'view' => true, 'create' => true, 'update' => true, 'delete' => true, 'restore' => true]],
    'agent' => [OrganizationRole::Agent, ['viewAny' => true, 'view' => true, 'create' => false, 'update' => false, 'delete' => false, 'restore' => false]],
    'property-owner' => [OrganizationRole::PropertyOwner, ['viewAny' => false, 'view' => false, 'create' => false, 'update' => false, 'delete' => false, 'restore' => false]],
    'tenant' => [OrganizationRole::Tenant, ['viewAny' => false, 'view' => false, 'create' => false, 'update' => false, 'delete' => false, 'restore' => false]],
]);

it('grants the owner permissions to the roles ARCHITECTURE.md § 5 names, and classes them for the central guards', function () {
    $holders = fn (OrganizationPermission $permission): array => array_values(array_map(
        fn (OrganizationRole $role): string => $role->value,
        array_filter(OrganizationRole::cases(), fn (OrganizationRole $role): bool => in_array($permission, $role->permissions(), true)),
    ));

    expect($holders(OrganizationPermission::ViewOwners))->toBe(['org-owner', 'org-admin', 'agent'])
        ->and($holders(OrganizationPermission::ManageOwners))->toBe(['org-owner', 'org-admin'])
        ->and(OrganizationPermission::ViewOwners->allowedWhileSuspended())->toBeTrue()
        ->and(OrganizationPermission::ManageOwners->allowedWhileSuspended())->toBeFalse()
        ->and(OrganizationPermission::ViewOwners->blockedWhileImpersonating())->toBeFalse()
        ->and(OrganizationPermission::ManageOwners->blockedWhileImpersonating())->toBeFalse();
});

it('refuses everything to a member of another organization and to strangers', function () {
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $outsider = joinAs(User::factory()->create(), $other);
    $stranger = User::factory()->create();

    foreach ([$outsider, $stranger] as $user) {
        expect(array_filter(ownerAbilitiesOf($user, $this->acme, $this->record, $this->archived)))->toBe([]);
    }

    // Their own workspace does not reach Acme's owners either: the scope hides them.
    expect(app(OrganizationContext::class)->run($other, fn () => OwnerProfile::query()->find($this->record->id)))->toBeNull();
});

it('opens the pages only for the roles allowed, and 404 outside the workspace', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $landlord = joinAs(User::factory()->create(), $this->acme, OrganizationRole::PropertyOwner);
    $tenant = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Tenant);

    foreach ([$this->owner, $admin] as $user) {
        ownersPage($user)->assertOk()->assertSee('Tunde Bakare')->assertSee('data-test="add-owner"', false)->assertSee('data-test="archive-'.$this->record->id.'"', false);
        ownersPage($user, '/create')->assertOk();
        ownersPage($user, "/{$this->record->id}")->assertOk()->assertSee('Save owner');
    }

    // Agents look owners up, read-only.
    ownersPage($agent)->assertOk()->assertSee('Tunde Bakare')->assertDontSee('data-test="add-owner"', false)->assertDontSee('data-test="archive-', false);
    ownersPage($agent, "/{$this->record->id}")->assertOk()->assertDontSee('Save owner')->assertSee('You can see these details but not change them.');
    ownersPage($agent, '/create')->assertForbidden();

    // Property owners and tenants never open the workspace (ARCHITECTURE.md § 5).
    foreach ([$landlord, $tenant, User::factory()->create()] as $user) {
        ownersPage($user)->assertNotFound();
        ownersPage($user, "/{$this->record->id}")->assertNotFound();
    }
});

it('refuses an agent\'s save, archive and restore sent straight to the server', function () {
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);

    $form = ownersSnapshot($agent, "/{$this->record->id}");
    LivewireHttp::update($this, $form, ['firstName' => 'Changed'], [['save']])->assertForbidden();

    $list = ownersSnapshot($agent);
    LivewireHttp::update($this, $list, [], [['archive', [$this->record->id]]])->assertForbidden();
    LivewireHttp::update($this, $list, [], [['restore', [$this->archived->id]]])->assertForbidden();

    $fresh = ownersOf($this->acme);
    expect($fresh->firstWhere('id', $this->record->id)->first_name)->toBe('Tunde')
        ->and($fresh->firstWhere('id', $this->record->id)->trashed())->toBeFalse()
        ->and($fresh->firstWhere('id', $this->archived->id)->trashed())->toBeTrue();
});

it('shows owners read-only while the organization is suspended', function () {
    $form = ownersSnapshot($this->owner, "/{$this->record->id}");
    $list = ownersSnapshot($this->owner);
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    ownersPage($this->owner)->assertOk()->assertSee('Tunde Bakare')->assertDontSee('data-test="add-owner"', false)->assertDontSee('data-test="archive-', false);
    ownersPage($this->owner, "/{$this->record->id}")->assertOk()->assertDontSee('Save owner');
    ownersPage($this->owner, '/create')->assertForbidden();
    LivewireHttp::update($this, $form, ['firstName' => 'Changed'], [['save']])->assertForbidden();
    LivewireHttp::update($this, $list, [], [['archive', [$this->record->id]]])->assertForbidden();

    expect(ownersOf($this->acme)->firstWhere('id', $this->record->id)->first_name)->toBe('Tunde');
});

it('lets impersonating staff in write mode edit an owner, audited with both identities, but never archive one', function () {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write))->assertRedirect('/o/acme-homes');

    $form = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/owners/{$this->record->id}")->assertOk());
    freshRequest();
    LivewireHttp::update(test(), $form, ['lastName' => 'Bakare-Lawal'], [['save']])->assertOk();

    $list = LivewireHttp::snapshot(asSupport()->get('/o/acme-homes/owners')->assertOk());
    freshRequest();
    LivewireHttp::update(test(), $list, [], [['archive', [$this->record->id]]])->assertForbidden();

    $record = ownersOf($this->acme)->firstWhere('id', $this->record->id);
    $edit = AuditEntry::query()->where('log_name', 'owners')->where('event', 'owner.updated')->sole();

    expect($record->last_name)->toBe('Bakare-Lawal')
        ->and($record->trashed())->toBeFalse()
        ->and($edit->impersonator_staff_id)->toBe($staff->id)
        ->and($edit->causer_id)->toBe($this->owner->id)
        ->and(AuditEntry::query()->where('log_name', 'owners')->where('event', 'owner.archived')->exists())->toBeFalse();
});
