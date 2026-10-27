<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\ApprovalRequest;
use App\Models\StaffRole;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Filament\Pages\Settings\BrandAppearance;
use App\Platform\Filament\Pages\Settings\CustomCode;
use App\Platform\Filament\Pages\Settings\SignInSecuritySettings;
use App\Platform\Filament\Resources\StaffRoles\Pages\CreateStaffRole;
use App\Platform\Filament\Resources\StaffRoles\Pages\EditStaffRole;
use App\Platform\Filament\Resources\StaffRoles\Pages\ListStaffRoles;
use App\Platform\Staff\DefaultStaffRole;
use App\Platform\Staff\StaffChangeRefused;
use App\Platform\Staff\StaffPermission;
use App\Platform\Staff\StaffRoles;
use Database\Seeders\StaffRolesSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/*
 * P01-07: configurable staff roles (PLATFORM_CONSOLE.md § 1): the default
 * set and custom roles from the permission catalogue, protected rules, and
 * every change audited.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    $this->ada = staffNamed('Ada', ['super-admin']);
    $this->bola = staffNamed('Bola', ['super-admin']);
    $this->roles = app(StaffRoles::class);
    $this->console = '/'.config('app.admin_path');
});

function namedRole(string $name): StaffRole
{
    return StaffRole::query()->where('name', $name)->firstOrFail();
}

it('seeds the catalogue and the default roles on the staff guard, outside any organization', function () {
    expect(Permission::query()->where('guard_name', 'staff')->pluck('name')->sort()->values()->all())
        ->toBe(collect(StaffPermission::values())->sort()->values()->all())
        ->and(StaffRole::query()->pluck('name')->sort()->values()->all())->toBe(collect(DefaultStaffRole::values())->sort()->values()->all())
        ->and(StaffRole::query()->whereNotNull('organization_id')->count())->toBe(0)
        ->and(StaffRole::query()->where('is_default', false)->count())->toBe(0)
        ->and(StaffRole::query()->where('is_protected', true)->pluck('name')->all())->toBe(['super-admin']);

    foreach (DefaultStaffRole::cases() as $default) {
        expect(namedRole($default->value)->permissionNames())
            ->toEqualCanonicalizing(array_map(fn (StaffPermission $permission): string => $permission->value, $default->permissions()));
    }

    // Impersonation grants from § 6, ready for P01-08; system.status for the heartbeat.
    $holders = fn (string $permission): array => StaffRole::query()->whereHas('permissions', fn ($query) => $query->where('name', $permission))->pluck('name')->sort()->values()->all();

    expect($holders('impersonate.read_only'))->toBe(['operations-manager', 'super-admin', 'support-agent'])
        ->and($holders('impersonate.write'))->toBe(['super-admin'])
        ->and($holders('impersonation.view_log'))->toBe(['compliance-officer', 'operations-manager', 'super-admin'])
        ->and($holders('system.status'))->toBe(['super-admin', 'technical-admin'])
        ->and(StaffPermission::ImpersonateWrite->isProtected())->toBeTrue()
        ->and(StaffPermission::ImpersonateReadOnly->isProtected())->toBeFalse();
});

it('never undoes a super-admin\'s edits when seeding again, but keeps super-admin whole', function () {
    $this->roles->updateRole($this->ada, namedRole('analyst'), 'analyst', 'Reports only', ['analytics.view', 'customers.view']);
    namedRole('super-admin')->revokePermissionTo('templates.edit');

    $this->seed(StaffRolesSeeder::class);

    expect(namedRole('analyst')->permissionNames())->toEqualCanonicalizing(['analytics.view', 'customers.view'])
        ->and(namedRole('analyst')->description)->toBe('Reports only')
        ->and(namedRole('super-admin')->permissionNames())->toContain('templates.edit');
});

it('gives each console page by permission: the settings pages follow the roles', function () {
    $content = staffNamed('Chika', ['content-manager']);
    $analyst = staffNamed('Dayo', ['analyst']);

    $this->actingAs($content, 'staff');
    $this->get($this->console.'/settings/brand')->assertOk();
    $this->get($this->console.'/settings/custom-code')->assertForbidden();
    $this->get($this->console.'/settings/sign-in-security')->assertForbidden();
    $this->get($this->console.'/staff')->assertForbidden();
    $this->get($this->console.'/staff-roles')->assertForbidden();
    $this->get($this->console.'/approvals')->assertForbidden();

    $this->actingAs($analyst, 'staff');
    expect(BrandAppearance::canAccess())->toBeFalse()
        ->and(CustomCode::canAccess())->toBeFalse()
        ->and(SignInSecuritySettings::canAccess())->toBeFalse();

    $this->actingAs($this->ada, 'staff');
    expect(CustomCode::canAccess())->toBeTrue()
        ->and(SignInSecuritySettings::canAccess())->toBeTrue();
});

it('creates a custom role from the catalogue in the console', function () {
    $this->actingAs($this->ada, 'staff');

    Livewire::test(CreateStaffRole::class)
        ->fillForm([
            'name' => 'Regional Support Lead',
            'description' => 'Support plus delivery logs',
            'permissions_by_area' => ['Customers' => ['customers.view'], 'Support' => ['emails.resend', 'delivery_logs.view']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = namedRole('Regional Support Lead');

    expect($role->permissionNames())->toEqualCanonicalizing(['customers.view', 'emails.resend', 'delivery_logs.view'])
        ->and($role->is_default)->toBeFalse()
        ->and(AuditEntry::query()->where('event', 'role.created')->where('subject_id', $role->id)->sole()->causer_id)->toBe($this->ada->id);

    $kemi = staffNamed('Kemi');
    $this->roles->grant($this->ada, $kemi, $role);

    expect($kemi->fresh()?->holdsStaffPermission(StaffPermission::EmailsResend))->toBeTrue()
        ->and($kemi->fresh()?->holdsStaffPermission(StaffPermission::RefundsCreate))->toBeFalse();
});

it('renames custom roles and changes permissions, audited with before and after', function () {
    [$role] = $this->roles->createRole($this->ada, 'Night shift', null, ['customers.view']);
    $this->actingAs($this->ada, 'staff');

    Livewire::test(EditStaffRole::class, ['record' => $role->id])
        ->assertFormSet(['name' => 'Night shift', 'permissions_by_area.Customers' => ['customers.view'], 'permissions_by_area.Support' => []])
        ->fillForm(['name' => 'Night support', 'permissions_by_area' => ['Customers' => [], 'Support' => ['delivery_logs.view']]])
        ->call('save')
        ->assertHasNoFormErrors();

    $entry = AuditEntry::query()->where('event', 'role.updated')->where('subject_id', $role->id)->sole();

    expect($role->fresh()?->name)->toBe('Night support')
        ->and($role->fresh()?->permissionNames())->toBe(['delivery_logs.view'])
        ->and($entry->properties['name'])->toEqual(['old' => 'Night shift', 'new' => 'Night support'])
        ->and($entry->properties['permissions_added'])->toBe(['delivery_logs.view'])
        ->and($entry->properties['permissions_removed'])->toBe(['customers.view']);
});

it('keeps default role names, and never lets anyone edit or deactivate super-admin', function () {
    expect(fn () => $this->roles->updateRole($this->ada, namedRole('analyst'), 'Analysts', null, ['analytics.view']))->toThrow(StaffChangeRefused::class, 'keep their names')
        ->and(fn () => $this->roles->updateRole($this->ada, namedRole('super-admin'), 'super-admin', null, []))->toThrow(StaffChangeRefused::class, 'super-admin role cannot')
        ->and(fn () => $this->roles->deactivateRole($this->ada, namedRole('super-admin')))->toThrow(StaffChangeRefused::class)
        ->and(Gate::forUser($this->ada)->allows('update', namedRole('super-admin')))->toBeFalse()
        ->and(Gate::forUser($this->ada)->allows('delete', namedRole('analyst')))->toBeFalse();

    $this->actingAs($this->ada, 'staff')->get($this->console.'/staff-roles/'.namedRole('super-admin')->id.'/edit')->assertForbidden();
    $this->actingAs($this->ada, 'staff')->get($this->console.'/staff-roles/'.namedRole('analyst')->id.'/edit')->assertOk();
});

it('adds protected permissions only by a super-admin, and only after a second super-admin approves', function () {
    $role = namedRole('technical-admin');

    $request = $this->roles->updateRole($this->ada, $role, 'technical-admin', null, [...$role->permissionNames(), 'backups.approve_production_restore', 'audit.view']);

    expect($request)->toBeInstanceOf(ApprovalRequest::class)
        ->and($role->fresh()?->permissionNames())->toContain('audit.view')
        ->and($role->fresh()?->permissionNames())->not->toContain('backups.approve_production_restore');

    expect(fn () => app(ApprovalService::class)->approve($request, $this->ada))->toThrow(Exception::class, 'your own request');

    app(ApprovalService::class)->approve($request, $this->bola);

    expect($role->fresh()?->permissionNames())->toContain('backups.approve_production_restore')
        ->and(AuditEntry::query()->where('event', 'role.permissions_added')->where('subject_id', $role->id)->sole()->causer_id)->toBe($this->bola->id);

    // A non-super-admin holding roles.manage still cannot add protected permissions.
    [$managers, $pending] = $this->roles->createRole($this->ada, 'Role managers', null, ['roles.manage']);
    app(ApprovalService::class)->approve($pending, $this->bola);
    $dayo = staffNamed('Dayo');
    app(ApprovalService::class)->approve($this->roles->grant($this->ada, $dayo, $managers->fresh()), $this->bola);

    expect($dayo->fresh()?->holdsStaffPermission(StaffPermission::RolesManage))->toBeTrue()
        ->and(fn () => $this->roles->updateRole($dayo->fresh(), namedRole('analyst'), 'analyst', null, ['analytics.view', 'data.purge']))
        ->toThrow(StaffChangeRefused::class, 'Only a super-admin');
});

it('deactivates a role only when nobody holds it, and a deactivated role grants nothing', function () {
    [$role] = $this->roles->createRole($this->ada, 'Temp team', null, ['customers.view']);
    $kemi = staffNamed('Kemi');
    $this->roles->grant($this->ada, $kemi, $role);

    expect(fn () => $this->roles->deactivateRole($this->ada, $role))->toThrow(StaffChangeRefused::class, 'Move them to another role first');

    $this->roles->revoke($this->ada, $kemi, $role);
    $this->actingAs($this->ada, 'staff');

    Livewire::test(ListStaffRoles::class)
        ->callTableAction('deactivate', $role)
        ->assertNotified('Temp team deactivated.');

    expect($role->fresh()?->active)->toBeFalse()
        ->and(fn () => $this->roles->grant($this->ada, $kemi, $role->fresh()))->toThrow(StaffChangeRefused::class, 'deactivated')
        ->and(AuditEntry::query()->where('event', 'role.deactivated')->where('subject_id', $role->id)->exists())->toBeTrue();

    // A role switched off by other means stops granting at once.
    $support = namedRole('support-agent');
    $this->roles->grant($this->ada, $kemi, $support);
    expect($kemi->fresh()?->holdsStaffPermission(StaffPermission::CustomersView))->toBeTrue();
    $support->forceFill(['active' => false])->save();
    expect($kemi->fresh()?->holdsStaffPermission(StaffPermission::CustomersView))->toBeFalse();
});

it('lists roles to staff who may see them, and offers changes only with roles.manage', function () {
    $operations = staffNamed('Ope', ['operations-manager']);
    $this->actingAs($operations, 'staff')->get($this->console.'/staff-roles')->assertForbidden();

    [$role] = $this->roles->createRole($this->ada, 'Viewers', null, ['staff.view']);
    $viewer = staffNamed('Vera');
    $this->roles->grant($this->ada, $viewer, $role);

    $this->flushSession();
    $this->actingAs($viewer->fresh(), 'staff')->get($this->console.'/staff-roles')->assertOk();
    Livewire::test(ListStaffRoles::class)
        ->assertActionHidden('create')
        ->assertTableActionHidden('edit', namedRole('analyst'))
        ->assertTableActionHidden('deactivate', $role);
    $this->get($this->console.'/staff-roles/create')->assertForbidden();
});
