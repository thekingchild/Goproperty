<?php

declare(strict_types=1);

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Hosting\Queue\QueueTick;
use App\Hosting\Queue\TickStopReason;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\CrossOrganizationWrite;
use App\Tenancy\MissingOrganizationContext;
use App\Tenancy\OrganizationContext;
use Database\Seeders\OrganizationRolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Permission;
use Tests\Fixtures\Jobs\RecordPermissionTeam;

/*
 * P01-01: roles per organization through spatie teams (ARCHITECTURE.md § 5).
 * The team is always OrganizationContext's organization, never left over
 * from another request or job; staff roles (P01-07) are global.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->context = app(OrganizationContext::class);
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty']);
    RecordPermissionTeam::$seen = [];
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
});

it('seeds the customer roles from the catalogue, shared by every organization', function () {
    $roles = Role::query()->where('guard_name', 'web')->orderBy('name')->get();

    expect($roles->pluck('name')->all())->toBe(['agent', 'org-admin', 'org-owner', 'property-owner', 'tenant'])
        ->and($roles->pluck('organization_id')->unique()->all())->toBe([null])
        ->and(Permission::query()->where('guard_name', 'web')->pluck('name')->sort()->values()->all())
        ->toBe(collect(OrganizationPermission::values())->sort()->values()->all());

    foreach (OrganizationRole::cases() as $role) {
        $granted = Role::query()->whereNull('organization_id')->where('name', $role->value)->firstOrFail()->permissions->pluck('name')->sort()->values()->all();

        expect($granted)->toBe(collect($role->permissions())->map->value->sort()->values()->all());
    }

    expect(OrganizationRole::Owner->opensWorkspace())->toBeTrue()
        ->and(OrganizationRole::Admin->opensWorkspace())->toBeTrue()
        ->and(OrganizationRole::Agent->opensWorkspace())->toBeTrue()
        ->and(OrganizationRole::PropertyOwner->opensWorkspace())->toBeFalse()
        ->and(OrganizationRole::Tenant->opensWorkspace())->toBeFalse()
        ->and(OrganizationRole::Admin->permissions())->not->toContain(OrganizationPermission::ManageBilling, OrganizationPermission::DeleteOrganization, OrganizationPermission::ManagePaymentSettings)
        ->and(OrganizationRole::Tenant->permissions())->not->toContain(OrganizationPermission::ConfirmPayments);
});

it('re-seeds without duplicates and puts back a removed grant', function () {
    $owner = Role::query()->whereNull('organization_id')->where('name', 'org-owner')->firstOrFail();
    $owner->revokePermissionTo(OrganizationPermission::ManageBilling->value);

    $this->seed(OrganizationRolesSeeder::class);

    expect(Role::query()->where('guard_name', 'web')->count())->toBe(5)
        ->and(Permission::query()->where('guard_name', 'web')->count())->toBe(count(OrganizationPermission::cases()))
        ->and($owner->fresh()?->hasPermissionTo(OrganizationPermission::ManageBilling->value))->toBeTrue();
});

it('takes the permission team from the organization context only', function () {
    expect(getPermissionsTeamId())->toBeNull();

    $this->context->run($this->acme, function () {
        expect(getPermissionsTeamId())->toBe($this->acme->id);

        $this->context->run($this->other, fn () => expect(getPermissionsTeamId())->toBe($this->other->id));
        $this->context->outside(fn () => expect(getPermissionsTeamId())->toBeNull());

        expect(getPermissionsTeamId())->toBe($this->acme->id);
    });

    expect(getPermissionsTeamId())->toBeNull()
        ->and(fn () => setPermissionsTeamId($this->acme->id))->toThrow(LogicException::class, 'OrganizationContext::run()');
});

it('starts every request and job without a team', function () {
    $this->context->set($this->acme);
    expect(getPermissionsTeamId())->toBe($this->acme->id);

    app()->forgetScopedInstances();

    expect(getPermissionsTeamId())->toBeNull();
});

it('does not carry the team from one request to the next', function () {
    Route::middleware(['web', 'auth', 'organization'])->get('o/{organization}/team-probe', fn () => response()->json(['team' => getPermissionsTeamId()]));
    Route::middleware(['web', 'auth'])->get('team-probe', fn () => response()->json(['team' => getPermissionsTeamId()]));
    $user = joinAs(User::factory()->create(), $this->acme);
    joinAs($user, $this->other, OrganizationRole::Agent);

    $this->actingAs($user)->getJson('/o/acme-homes/team-probe')->assertExactJson(['team' => $this->acme->id]);
    $this->actingAs($user)->getJson('/team-probe')->assertExactJson(['team' => null]);
    $this->actingAs($user)->getJson('/o/other-realty/team-probe')->assertExactJson(['team' => $this->other->id]);
});

it('does not carry the team into or between queued jobs', function () {
    $this->context->run($this->acme, function () {
        RecordPermissionTeam::dispatch('dispatched-inside-acme')->onConnection('database')->onQueue('default');
        RecordPermissionTeam::dispatch('works-in-other', $this->other->id)->onConnection('database')->onQueue('default');
    });
    RecordPermissionTeam::dispatch('next-job')->onConnection('database')->onQueue('default');

    expect(app(QueueTick::class)->run()->stoppedBy)->toBe(TickStopReason::Drained);

    expect(RecordPermissionTeam::$seen)->toBe([
        ['label' => 'dispatched-inside-acme', 'at_start' => null, 'inside' => null],
        ['label' => 'works-in-other', 'at_start' => null, 'inside' => $this->other->id],
        ['label' => 'next-job', 'at_start' => null, 'inside' => null],
    ]);
});

it('assigns a role per organization', function () {
    $user = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Owner);
    joinAs($user, $this->other, OrganizationRole::Agent);

    expect($user->roleNamesIn($this->acme))->toBe(['org-owner'])
        ->and($user->roleNamesIn($this->other))->toBe(['agent'])
        ->and($user->hasRoleIn($this->acme, OrganizationRole::Agent))->toBeFalse()
        ->and($user->roleNamesByOrganization())->toEqual([$this->acme->id => ['org-owner'], $this->other->id => ['agent']])
        ->and(DB::table('model_has_roles')->where('model_id', $user->id)->orderBy('organization_id')->pluck('organization_id')->all())
        ->toBe([$this->acme->id, $this->other->id]);
});

it('never answers with roles loaded for another organization', function () {
    $user = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Owner);
    joinAs($user, $this->other, OrganizationRole::Tenant);
    $user = $user->fresh();

    $this->context->run($this->acme, fn () => expect($user->roles->pluck('name')->all())->toBe(['org-owner'])
        ->and(Gate::forUser($user)->allows(OrganizationPermission::ConfirmPayments->value))->toBeTrue());

    // The same model instance, now in another organization.
    $this->context->run($this->other, fn () => expect($user->roles->pluck('name')->all())->toBe(['tenant'])
        ->and($user->hasRole('org-owner'))->toBeFalse()
        ->and(Gate::forUser($user)->allows(OrganizationPermission::ConfirmPayments->value))->toBeFalse());

    $this->context->run($this->acme, fn () => expect($user->hasRole('org-owner'))->toBeTrue());
});

it('refuses roles outside an organization or for non-members', function () {
    $user = User::factory()->create();

    expect(fn () => $user->assignRole('org-owner'))->toThrow(MissingOrganizationContext::class)
        ->and(fn () => $this->context->run($this->acme, fn () => $user->assignRole('org-owner')))->toThrow(CrossOrganizationWrite::class, 'member')
        ->and(fn () => $this->context->run($this->acme, fn () => $user->givePermissionTo(OrganizationPermission::ManageBilling->value)))->toThrow(CrossOrganizationWrite::class)
        ->and(DB::table('model_has_roles')->count())->toBe(0);
});

it('lets staff hold global roles on the staff guard, outside any organization', function () {
    $staff = staffMember(roles: []);
    $permission = Permission::create(['name' => 'customers.view', 'guard_name' => 'staff']);
    $role = Role::create(['name' => 'support-agent', 'guard_name' => 'staff']);
    $role->givePermissionTo($permission);

    $staff->assignRole('support-agent');

    expect($role->organization_id)->toBeNull()
        ->and(DB::table('model_has_roles')->where('model_type', $staff->getMorphClass())->get(['role_id', 'organization_id'])->map(fn ($row) => (array) $row)->all())
        ->toBe([['role_id' => $role->id, 'organization_id' => null]])
        ->and($staff->fresh()?->hasRole('support-agent'))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('customers.view'))->toBeTrue();
});

it('keeps staff roles global even inside an organization context', function () {
    $staff = staffMember(roles: []);
    Role::create(['name' => 'support-agent', 'guard_name' => 'staff']);
    Role::create(['name' => 'analyst', 'guard_name' => 'staff']);
    $staff->assignRole('support-agent');

    // e.g. a staff check while impersonating a customer (P01-08).
    $this->context->run($this->acme, function () use ($staff) {
        $staff->assignRole('analyst');

        expect($staff->fresh()?->hasRole('support-agent'))->toBeTrue()
            ->and($staff->getRoleNames()->sort()->values()->all())->toBe(['analyst', 'support-agent']);
    });

    expect(DB::table('model_has_roles')->where('model_type', $staff->getMorphClass())->whereNotNull('organization_id')->count())->toBe(0)
        ->and(Role::query()->where('guard_name', 'staff')->whereNotNull('organization_id')->count())->toBe(0);
});

it('keeps customer and staff roles apart', function () {
    $user = joinAs(User::factory()->create(), $this->acme);
    $staff = staffMember(roles: []);

    expect(fn () => $staff->assignRole('org-owner'))->toThrow(RoleDoesNotExist::class)
        ->and(fn () => $this->context->run($this->acme, fn () => $user->hasPermissionTo('customers.view', 'staff')))->toThrow(PermissionDoesNotExist::class);
});
