<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Support\Facades\Gate;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Tenants\Models\TenantProfile;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P03-01a: who may see and change tenants on the pages (ARCHITECTURE.md
 * § 5, TenantProfilePolicy): owners, administrators and agents see and
 * manage tenants; property owners and tenants never open the workspace.
 * The central guards apply on top: a suspended organization is read-only,
 * impersonating staff never archive a tenant nor take the list away.
 * Agents narrowed to some buildings still see every tenant until leases
 * tie tenants to buildings (P03-03a; decision recorded in the ledger).
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->record = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare']);
    $this->archived = tenantIn($this->acme, ['first_name' => 'Old', 'last_name' => 'Tenant']);
    inOrganization($this->acme, fn () => $this->archived->delete());
});

afterEach(fn () => tenantPagesFreshRequest());

/** @return array<string, bool> ability => allowed, as the policy answers inside the organization */
function tenantPageAbilitiesOf(User $user, Organization $organization, TenantProfile $record, TenantProfile $archived): array
{
    return inOrganization($organization, fn (): array => [
        'viewAny' => Gate::forUser($user)->allows('viewAny', TenantProfile::class),
        'view' => Gate::forUser($user)->allows('view', $record),
        'viewArchived' => Gate::forUser($user)->allows('view', $archived),
        'export' => Gate::forUser($user)->allows('export', TenantProfile::class),
        'create' => Gate::forUser($user)->allows('create', TenantProfile::class),
        'update' => Gate::forUser($user)->allows('update', $record),
        'delete' => Gate::forUser($user)->allows('delete', $record),
        'restore' => Gate::forUser($user)->allows('restore', $archived),
        'updateArchived' => Gate::forUser($user)->allows('update', $archived),
    ]);
}

it('enforces the ARCHITECTURE.md § 5 role rules in TenantProfilePolicy', function (OrganizationRole $role, bool $allowed) {
    $user = joinAs(User::factory()->create(), $this->acme, $role);

    expect(tenantPageAbilitiesOf($user, $this->acme, $this->record, $this->archived))->toBe([
        'viewAny' => $allowed, 'view' => $allowed, 'viewArchived' => $allowed, 'export' => $allowed,
        'create' => $allowed, 'update' => $allowed, 'delete' => $allowed, 'restore' => $allowed,
        'updateArchived' => false,
    ]);
})->with([
    'org-owner' => [OrganizationRole::Owner, true],
    'org-admin' => [OrganizationRole::Admin, true],
    'agent' => [OrganizationRole::Agent, true],
    'property-owner' => [OrganizationRole::PropertyOwner, false],
    'tenant' => [OrganizationRole::Tenant, false],
]);

it('opens the pages for owners, administrators and agents, and 404 for property owners, tenants and strangers', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $landlord = joinAs(User::factory()->create(), $this->acme, OrganizationRole::PropertyOwner);
    $renter = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Tenant);

    foreach ([$this->owner, $admin, $agent] as $user) {
        tenantsPage($user)->assertOk()->assertSee('Tunde Bakare')
            ->assertSee('data-test="add-tenant"', false)->assertSee('data-test="archive-'.$this->record->id.'"', false)
            ->assertSee('data-test="export-tenants-csv"', false);
        tenantsPage($user, '/create')->assertOk();
        tenantsPage($user, "/{$this->record->id}")->assertOk()->assertSee('data-test="edit-tenant"', false)->assertSee('data-test="archive-tenant"', false);
        tenantsPage($user, "/{$this->record->id}/edit")->assertOk()->assertSee('Save tenant');
    }

    // An agent adds a tenant (they sign leases, P02-06b), audited as themselves.
    LivewireHttp::update($this, tenantsSnapshot($agent, '/create'), tenantForm(), [['save']])->assertOk();
    expect(AuditEntry::query()->where('event', 'tenant.created')->sole()->causer_id)->toBe($agent->id);

    foreach ([$landlord, $renter, User::factory()->create()] as $user) {
        tenantsPage($user)->assertNotFound();
        tenantsPage($user, '/create')->assertNotFound();
        tenantsPage($user, "/{$this->record->id}")->assertNotFound();
    }
});

it('shows tenants in the sidebar to the roles that may see them', function () {
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);

    tenantsPage($agent)->assertOk()->assertSee('data-nav="tenants"', false)->assertSee('href="http://localhost/o/acme-homes/tenants"', false);
});

it('shows tenants read-only while the organization is suspended, and still exports them', function () {
    $form = tenantsSnapshot($this->owner, "/{$this->record->id}/edit");
    $show = tenantsSnapshot($this->owner, "/{$this->record->id}");
    $list = tenantsSnapshot($this->owner);
    $archivedList = tenantsSnapshot($this->owner, '?archived=1');
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    tenantsPage($this->owner)->assertOk()->assertSee('Tunde Bakare')
        ->assertDontSee('data-test="add-tenant"', false)->assertDontSee('data-test="archive-', false)
        ->assertSee('data-test="export-tenants-csv"', false);
    tenantsPage($this->owner, "/{$this->record->id}")->assertOk()->assertDontSee('data-test="edit-tenant"', false)->assertDontSee('data-test="archive-tenant"', false);
    tenantsPage($this->owner, "/{$this->archived->id}")->assertOk()->assertDontSee('data-test="restore-tenant"', false);
    tenantsPage($this->owner, '/create')->assertForbidden();
    tenantsPage($this->owner, "/{$this->record->id}/edit")->assertForbidden();

    test()->actingAs($this->owner);
    LivewireHttp::update($this, $form, ['firstName' => 'Changed'], [['save']])->assertForbidden();
    LivewireHttp::update($this, $show, [], [['archive']])->assertForbidden();
    LivewireHttp::update($this, $list, [], [['archive', [$this->record->id]]])->assertForbidden();
    LivewireHttp::update($this, $archivedList, [], [['restore', [$this->archived->id]]])->assertForbidden();

    $fresh = tenantsOf($this->acme);
    expect($fresh->firstWhere('id', $this->record->id)->first_name)->toBe('Tunde')
        ->and($fresh->firstWhere('id', $this->record->id)->trashed())->toBeFalse()
        ->and($fresh->firstWhere('id', $this->archived->id)->trashed())->toBeTrue()
        ->and(AuditEntry::query()->where('log_name', 'tenants')->exists())->toBeFalse();

    // Exports are reads: they keep working (P02-07).
    $pressed = LivewireHttp::update($this, tenantsSnapshot($this->owner), [], [['export', ['tenants', 'csv']]])->assertOk();
    expect(LivewireHttp::redirect($pressed))->toContain('/exports/');
});

it('shows impersonating staff the tenants read-only in a read-only session, without the export', function () {
    $staff = staffMember(['name' => 'Kemi Support', 'email' => 'kemi@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme))->assertRedirect('/o/acme-homes');

    $list = asSupport()->get('/o/acme-homes/tenants')->assertOk()->assertSee('Tunde Bakare')
        ->assertDontSee('data-test="add-tenant"', false)->assertDontSee('data-test="export-tenants-csv"', false);
    asSupport()->get("/o/acme-homes/tenants/{$this->record->id}")->assertOk()->assertDontSee('data-test="edit-tenant"', false);
    asSupport()->get('/o/acme-homes/tenants/create')->assertForbidden();

    $snapshot = LivewireHttp::snapshot($list);
    freshRequest();
    LivewireHttp::update(test(), $snapshot, [], [['archive', [$this->record->id]]])->assertForbidden();
    freshRequest();
    LivewireHttp::update(test(), $snapshot, [], [['export', ['tenants', 'csv']]])->assertForbidden();

    expect(tenantsOf($this->acme)->firstWhere('id', $this->record->id)->trashed())->toBeFalse()
        ->and(AuditEntry::query()->where('log_name', 'exports')->exists())->toBeFalse();
});

it('lets impersonating staff in write mode edit a tenant, audited with both identities, but never archive one or export the list', function () {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    $handOff = startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write);
    enterSupport($handOff)->assertRedirect('/o/acme-homes');

    $form = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/tenants/{$this->record->id}/edit")->assertOk());
    freshRequest();
    LivewireHttp::update(test(), $form, ['lastName' => 'Bakare-Lawal'], [['save']])->assertOk();

    $show = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/tenants/{$this->record->id}")->assertOk()->assertDontSee('data-test="archive-tenant"', false));
    freshRequest();
    LivewireHttp::update(test(), $show, [], [['archive']])->assertForbidden();

    $list = LivewireHttp::snapshot(asSupport()->get('/o/acme-homes/tenants')->assertOk()->assertDontSee('data-test="export-tenants-csv"', false));
    freshRequest();
    LivewireHttp::update(test(), $list, [], [['archive', [$this->record->id]]])->assertForbidden();
    freshRequest();
    LivewireHttp::update(test(), $list, [], [['export', ['tenants', 'xlsx']]])->assertForbidden();

    $record = tenantsOf($this->acme)->firstWhere('id', $this->record->id);
    $edit = AuditEntry::query()->where('log_name', 'tenants')->where('event', 'tenant.updated')->sole();

    expect($record->last_name)->toBe('Bakare-Lawal')
        ->and($record->trashed())->toBeFalse()
        ->and($edit->impersonator_staff_id)->toBe($staff->id)
        ->and($edit->causer_id)->toBe($this->owner->id)
        ->and(AuditEntry::query()->where('event', 'tenant.archived')->exists())->toBeFalse()
        ->and(AuditEntry::query()->where('log_name', 'exports')->exists())->toBeFalse()
        ->and(AuditEntry::query()->where('event', 'impersonation.blocked')->where('subject_id', $handOff->session->id)->count())->toBeGreaterThanOrEqual(3);
});

it('shows an agent narrowed to some buildings every tenant until leases tie tenants to buildings (P03-03a)', function () {
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
    assignIn($this->acme, $agent, $palm);

    // The agent is narrowed (BuildingAccess), yet no tenant has a building yet.
    expect(inOrganization($this->acme, fn () => app(BuildingAccess::class)->limitedTo($agent)))->toBe([$palm->id]);

    tenantsPage($agent)->assertOk()->assertSee('Tunde Bakare');
    tenantsPage($agent, "/{$this->record->id}")->assertOk();
    tenantsPage($agent, "/{$this->archived->id}")->assertOk();
    LivewireHttp::update($this, tenantsSnapshot($agent, '/create'), tenantForm(), [['save']])->assertOk();

    expect(tenantsOf($this->acme))->toHaveCount(3);
});
