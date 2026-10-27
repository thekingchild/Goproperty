<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Gate;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../../tests/Support/impersonation.php';

/*
 * P02-02: who may see and change units (ARCHITECTURE.md § 5), enforced by
 * UnitPolicy on the building permissions: owners and administrators add,
 * archive and restore units (each one is billed); agents edit them;
 * property owners and tenants never open the workspace. A restricted
 * organization adds and restores none (ONBOARDING_COMPLIANCE.md § 5.5);
 * the central guards (suspension, impersonation) apply on top.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->building = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->unit = unitIn($this->acme, $this->building, ['unit_label' => 'Flat 1']);
    $this->archivedUnit = unitIn($this->acme, $this->building, ['unit_label' => 'Flat 2']);
    inOrganization($this->acme, fn () => $this->archivedUnit->delete());
    $this->oldBuilding = buildingIn($this->acme, ['name' => 'Old Yard', 'slug' => 'old-yard', 'status' => PropertyStatus::Archived]);
    $this->oldUnit = unitIn($this->acme, $this->oldBuilding, ['unit_label' => 'Old 1']);
});

afterEach(fn () => freshRequest());

/** @return array<string, bool> ability => allowed, as the policy answers inside the organization */
function unitAbilitiesOf(User $user, Organization $organization): array
{
    return app(OrganizationContext::class)->run($organization, function () use ($user): array {
        $building = Property::query()->where('slug', 'palm-court')->sole();
        $old = Property::query()->where('slug', 'old-yard')->sole();
        $unit = Unit::query()->where('unit_label', 'Flat 1')->sole();
        $archived = Unit::query()->onlyTrashed()->where('unit_label', 'Flat 2')->sole();
        $oldUnit = Unit::query()->where('unit_label', 'Old 1')->sole();
        $gate = Gate::forUser($user);

        return [
            'viewAny' => $gate->allows('viewAny', Unit::class),
            'view' => $gate->allows('view', $unit),
            'create' => $gate->allows('create', [Unit::class, $building]),
            'update' => $gate->allows('update', $unit),
            'delete' => $gate->allows('delete', $unit),
            'restore' => $gate->allows('restore', $archived),
            'createInArchivedBuilding' => $gate->allows('create', [Unit::class, $old]),
            'updateInArchivedBuilding' => $gate->allows('update', $oldUnit),
            'deleteInArchivedBuilding' => $gate->allows('delete', $oldUnit),
        ];
    });
}

it('enforces the ARCHITECTURE.md § 5 role rules in UnitPolicy', function (OrganizationRole $role, array $expected) {
    $user = joinAs(User::factory()->create(), $this->acme, $role);

    expect(unitAbilitiesOf($user, $this->acme))->toBe($expected);
})->with([
    'org-owner' => [OrganizationRole::Owner, ['viewAny' => true, 'view' => true, 'create' => true, 'update' => true, 'delete' => true, 'restore' => true, 'createInArchivedBuilding' => false, 'updateInArchivedBuilding' => false, 'deleteInArchivedBuilding' => false]],
    'org-admin' => [OrganizationRole::Admin, ['viewAny' => true, 'view' => true, 'create' => true, 'update' => true, 'delete' => true, 'restore' => true, 'createInArchivedBuilding' => false, 'updateInArchivedBuilding' => false, 'deleteInArchivedBuilding' => false]],
    'agent' => [OrganizationRole::Agent, ['viewAny' => true, 'view' => true, 'create' => false, 'update' => true, 'delete' => false, 'restore' => false, 'createInArchivedBuilding' => false, 'updateInArchivedBuilding' => false, 'deleteInArchivedBuilding' => false]],
    'property-owner' => [OrganizationRole::PropertyOwner, ['viewAny' => false, 'view' => false, 'create' => false, 'update' => false, 'delete' => false, 'restore' => false, 'createInArchivedBuilding' => false, 'updateInArchivedBuilding' => false, 'deleteInArchivedBuilding' => false]],
    'tenant' => [OrganizationRole::Tenant, ['viewAny' => false, 'view' => false, 'create' => false, 'update' => false, 'delete' => false, 'restore' => false, 'createInArchivedBuilding' => false, 'updateInArchivedBuilding' => false, 'deleteInArchivedBuilding' => false]],
]);

it('refuses everything to a member of another organization and to strangers', function () {
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $outsider = joinAs(User::factory()->create(), $other);

    foreach ([$outsider, User::factory()->create()] as $user) {
        expect(array_filter(unitAbilitiesOf($user, $this->acme)))->toBe([]);
    }

    expect(app(OrganizationContext::class)->run($other, fn () => Unit::query()->withTrashed()->find($this->unit->id)))->toBeNull();
});

it('opens the unit pages only for the roles allowed, and 404 outside the workspace', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $landlord = joinAs(User::factory()->create(), $this->acme, OrganizationRole::PropertyOwner);
    $tenant = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Tenant);

    foreach ([$this->owner, $admin] as $user) {
        buildingsPage($user, '/palm-court')->assertOk()->assertSee('Flat 1')
            ->assertSee('data-test="add-unit"', false)->assertSee('data-test="add-units-bulk"', false)
            ->assertSee('data-test="edit-unit-'.$this->unit->id.'"', false)->assertSee('data-test="archive-unit-'.$this->unit->id.'"', false);
        unitsPage($user, 'palm-court', '/create')->assertOk();
        unitsPage($user, 'palm-court', '/bulk')->assertOk();
        unitsPage($user, 'palm-court', "/{$this->unit->id}/edit")->assertOk()->assertSee('data-test="archive-unit"', false);
    }

    // Agents edit units, but neither add nor archive them.
    buildingsPage($agent, '/palm-court')->assertOk()->assertSee('Flat 1')
        ->assertSee('data-test="edit-unit-'.$this->unit->id.'"', false)
        ->assertDontSee('data-test="add-unit"', false)->assertDontSee('data-test="add-units-bulk"', false)->assertDontSee('data-test="archive-unit-', false);
    unitsPage($agent, 'palm-court', "/{$this->unit->id}/edit")->assertOk()->assertDontSee('data-test="archive-unit"', false);
    unitsPage($agent, 'palm-court', '/create')->assertForbidden();
    unitsPage($agent, 'palm-court', '/bulk')->assertForbidden();
    $agentBoard = LivewireHttp::html(LivewireHttp::update($this, buildingsSnapshot($agent, '/palm-court'), ['showArchivedUnits' => true])->assertOk());
    expect($agentBoard)->toContain('Flat 2')->not->toContain('data-test="restore-unit-');

    foreach ([$landlord, $tenant, User::factory()->create()] as $user) {
        unitsPage($user, 'palm-court', "/{$this->unit->id}/edit")->assertNotFound();
        unitsPage($user, 'palm-court', '/create')->assertNotFound();
    }
});

it('refuses an agent\'s add, archive and restore sent straight to the server, but saves an agent\'s edit', function () {
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $ownerForm = unitsSnapshot($this->owner, 'palm-court', '/create');
    $ownerBulk = unitsSnapshot($this->owner, 'palm-court', '/bulk');
    $ownerEdit = unitsSnapshot($this->owner, 'palm-court', "/{$this->unit->id}/edit");

    // Snapshots taken by the owner, replayed by the agent: refused.
    $this->actingAs($agent);
    LivewireHttp::update($this, $ownerForm, unitForm(['unitLabel' => 'Sneaky 1']), [['save']])->assertForbidden();
    LivewireHttp::update($this, $ownerBulk, bulkForm(['pattern' => 'Sneaky {n}']), [['save']])->assertForbidden();
    LivewireHttp::update($this, $ownerEdit, [], [['archive']])->assertForbidden();

    $board = buildingsSnapshot($agent, '/palm-court');
    LivewireHttp::update($this, $board, [], [['archiveUnit', [$this->unit->id]]])->assertForbidden();
    LivewireHttp::update($this, $board, [], [['restoreUnit', [$this->archivedUnit->id]]])->assertForbidden();

    LivewireHttp::update($this, unitsSnapshot($agent, 'palm-court', "/{$this->unit->id}/edit"), ['status' => 'under_maintenance'], [['save']])->assertOk();

    $units = unitsOf($this->acme);
    expect($units->pluck('unit_label')->all())->toBe(['Flat 1', 'Flat 2', 'Old 1'])
        ->and($units->firstWhere('id', $this->unit->id)->trashed())->toBeFalse()
        ->and($units->firstWhere('id', $this->unit->id)->status)->toBe(UnitStatus::UnderMaintenance)
        ->and($units->firstWhere('id', $this->archivedUnit->id)->trashed())->toBeTrue();
});

it('shows units read-only while the organization is suspended', function () {
    $form = unitsSnapshot($this->owner, 'palm-court', "/{$this->unit->id}/edit");
    $create = unitsSnapshot($this->owner, 'palm-court', '/create');
    $bulk = unitsSnapshot($this->owner, 'palm-court', '/bulk');
    $board = LivewireHttp::next(LivewireHttp::update($this, buildingsSnapshot($this->owner, '/palm-court'), ['showArchivedUnits' => true]));
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    buildingsPage($this->owner, '/palm-court')->assertOk()->assertSee('Flat 1')->assertSee('0 of 1 unit occupied (0%)')
        ->assertDontSee('data-test="add-unit"', false)->assertDontSee('data-test="add-units-bulk"', false)
        ->assertDontSee('data-test="edit-unit-', false)->assertDontSee('data-test="archive-unit-', false);
    unitsPage($this->owner, 'palm-court', "/{$this->unit->id}/edit")->assertForbidden();
    unitsPage($this->owner, 'palm-court', '/create')->assertForbidden();
    unitsPage($this->owner, 'palm-court', '/bulk')->assertForbidden();
    LivewireHttp::update($this, $form, ['floor' => '9'], [['save']])->assertForbidden();
    LivewireHttp::update($this, $form, [], [['archive']])->assertForbidden();
    LivewireHttp::update($this, $create, unitForm(['unitLabel' => 'Flat 3']), [['save']])->assertForbidden();
    LivewireHttp::update($this, $bulk, bulkForm(['start' => '3']), [['save']])->assertForbidden();
    LivewireHttp::update($this, $board, [], [['archiveUnit', [$this->unit->id]]])->assertForbidden();
    LivewireHttp::update($this, $board, [], [['restoreUnit', [$this->archivedUnit->id]]])->assertForbidden();

    $units = unitsOf($this->acme);
    expect($units)->toHaveCount(3)
        ->and($units->firstWhere('id', $this->unit->id)->floor)->not->toBe('9')
        ->and($units->firstWhere('id', $this->unit->id)->trashed())->toBeFalse()
        ->and($units->firstWhere('id', $this->archivedUnit->id)->trashed())->toBeTrue();
});

it('adds and restores no unit while the organization is restricted, but keeps editing and archiving', function () {
    $board = LivewireHttp::next(LivewireHttp::update($this, buildingsSnapshot($this->owner, '/palm-court'), ['showArchivedUnits' => true]));
    $bulk = unitsSnapshot($this->owner, 'palm-court', '/bulk');
    $this->acme->forceFill(['status' => OrganizationStatus::Restricted])->save();

    buildingsPage($this->owner, '/palm-court')->assertOk()->assertDontSee('data-test="add-unit"', false)->assertDontSee('data-test="add-units-bulk"', false);
    unitsPage($this->owner, 'palm-court', '/create')->assertForbidden();
    unitsPage($this->owner, 'palm-court', '/bulk')->assertForbidden();
    LivewireHttp::update($this, $bulk, bulkForm(['start' => '3']), [['save']])->assertForbidden();
    LivewireHttp::update($this, $board, [], [['restoreUnit', [$this->archivedUnit->id]]])->assertForbidden();

    LivewireHttp::update($this, unitsSnapshot($this->owner, 'palm-court', "/{$this->unit->id}/edit"), ['floor' => '3'], [['save']])->assertOk();
    LivewireHttp::update($this, buildingsSnapshot($this->owner, '/palm-court'), [], [['archiveUnit', [$this->unit->id]]])->assertOk();

    $units = unitsOf($this->acme);
    expect($units)->toHaveCount(3)
        ->and($units->firstWhere('id', $this->unit->id)->floor)->toBe('3')
        ->and($units->firstWhere('id', $this->unit->id)->trashed())->toBeTrue()
        ->and($units->firstWhere('id', $this->archivedUnit->id)->trashed())->toBeTrue();
});

it('lets impersonating staff in write mode add and edit units, audited with both identities, but never archive one', function () {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write))->assertRedirect('/o/acme-homes');

    $form = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/buildings/palm-court/units/{$this->unit->id}/edit")->assertOk());
    freshRequest();
    LivewireHttp::update(test(), $form, ['floor' => '4'], [['save']])->assertOk();

    $create = LivewireHttp::snapshot(asSupport()->get('/o/acme-homes/buildings/palm-court/units/create')->assertOk());
    freshRequest();
    LivewireHttp::update(test(), $create, unitForm(['unitLabel' => 'Flat 5']), [['save']])->assertOk();

    $form = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/buildings/palm-court/units/{$this->unit->id}/edit")->assertOk());
    freshRequest();
    LivewireHttp::update(test(), $form, [], [['archive']])->assertForbidden();

    $board = LivewireHttp::snapshot(asSupport()->get('/o/acme-homes/buildings/palm-court')->assertOk());
    freshRequest();
    LivewireHttp::update(test(), $board, [], [['archiveUnit', [$this->unit->id]]])->assertForbidden();

    $edit = AuditEntry::query()->where('log_name', 'properties')->where('event', 'unit.updated')->sole();
    $added = AuditEntry::query()->where('log_name', 'properties')->where('event', 'unit.created')->sole();
    expect($this->unit->fresh()->floor)->toBe('4')
        ->and($this->unit->fresh()->trashed())->toBeFalse()
        ->and($edit->impersonator_staff_id)->toBe($staff->id)
        ->and($edit->causer_id)->toBe($this->owner->id)
        ->and($added->impersonator_staff_id)->toBe($staff->id)
        ->and(AuditEntry::query()->where('event', 'unit.archived')->exists())->toBeFalse();
});

it('lets impersonating staff in read-only mode open units but change nothing', function () {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme))->assertRedirect('/o/acme-homes');

    $board = LivewireHttp::snapshot(asSupport()->get('/o/acme-homes/buildings/palm-court')->assertOk()->assertSee('Flat 1')
        ->assertDontSee('data-test="add-unit"', false)->assertDontSee('data-test="edit-unit-', false));
    freshRequest();
    LivewireHttp::update(test(), $board, [], [['archiveUnit', [$this->unit->id]]])->assertForbidden();
    asSupport()->get("/o/acme-homes/buildings/palm-court/units/{$this->unit->id}/edit")->assertForbidden();
    asSupport()->get('/o/acme-homes/buildings/palm-court/units/bulk')->assertForbidden();

    expect(unitsOf($this->acme))->toHaveCount(3)
        ->and($this->unit->fresh()->trashed())->toBeFalse();
});
