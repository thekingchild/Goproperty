<?php

declare(strict_types=1);

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Tenants\Enums\KycStatus;
use Modules\Tenants\Enums\TenantIdType;
use Modules\Tenants\Models\TenantProfile;

require_once __DIR__.'/helpers.php';

/*
 * P02-06b: the tenant profile itself (DATABASE_SCHEMA.md § 1) and who may
 * see and change it (ARCHITECTURE.md § 5), enforced by TenantProfilePolicy.
 * The tenant pages come in P03-01a; the policy is ready for them.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->record = tenantIn($this->acme, ['first_name' => 'Adaeze', 'last_name' => 'Okafor']);
    $this->archived = tenantIn($this->acme);
    inOrganization($this->acme, fn () => $this->archived->delete());
});

afterEach(fn () => app(OrganizationContext::class)->clear());

/** @return array<string, bool> ability => allowed, as the policy answers inside the organization */
function tenantAbilitiesOf(User $user, Organization $organization, TenantProfile $record, TenantProfile $archived): array
{
    return inOrganization($organization, fn (): array => [
        'viewAny' => Gate::forUser($user)->allows('viewAny', TenantProfile::class),
        'view' => Gate::forUser($user)->allows('view', $record),
        'create' => Gate::forUser($user)->allows('create', TenantProfile::class),
        'update' => Gate::forUser($user)->allows('update', $record),
        'delete' => Gate::forUser($user)->allows('delete', $record),
        'restore' => Gate::forUser($user)->allows('restore', $archived),
    ]);
}

it('keeps the phone number as E.164 and the email in lower case, whoever writes them', function () {
    $tenant = tenantIn($this->acme, ['phone' => '0803 123 4567', 'email' => ' Ada@Example.COM ']);

    expect($tenant->phone)->toBe('+2348031234567')
        ->and(DB::table('tenant_profiles')->where('id', $tenant->id)->value('phone'))->toBe('+2348031234567')
        ->and($tenant->email)->toBe('ada@example.com')
        ->and($tenant->kyc_status)->toBe(KycStatus::Unverified)
        ->and($tenant->id_type)->toBe(TenantIdType::None)
        ->and($tenant->fullName())->toBeString();

    expect(fn () => tenantIn($this->acme, ['phone' => 'not a phone']))->toThrow(InvalidArgumentException::class);
});

it('allows one tenant per phone number in an organization, archived tenants included, but the same number in another', function () {
    $other = Organization::factory()->create(['slug' => 'other-realty']);

    expect(fn () => tenantIn($this->acme, ['phone' => $this->archived->phone]))->toThrow(UniqueConstraintViolationException::class);
    expect(tenantIn($other, ['phone' => $this->record->phone])->organization_id)->toBe($other->id);
});

it('stores the ID number encrypted at rest and shows it only masked', function () {
    $tenant = tenantIn($this->acme, ['id_type' => TenantIdType::Nin, 'id_value' => '12345672109']);
    $raw = (string) DB::table('tenant_profiles')->where('id', $tenant->id)->value('id_value');

    expect($raw)->not->toContain('12345672109')
        ->and(Crypt::decryptString($raw))->toBe('12345672109')
        ->and($tenant->toArray())->not->toHaveKey('id_value')
        ->and($tenant->maskedId())->toBe('NIN ···2109')
        ->and($this->record->maskedId())->toBeNull();
});

it('enforces the ARCHITECTURE.md § 5 role rules in TenantProfilePolicy', function (OrganizationRole $role, array $expected) {
    $user = joinAs(User::factory()->create(), $this->acme, $role);

    expect(tenantAbilitiesOf($user, $this->acme, $this->record, $this->archived))->toBe($expected);
})->with([
    'org-owner' => [OrganizationRole::Owner, ['viewAny' => true, 'view' => true, 'create' => true, 'update' => true, 'delete' => true, 'restore' => true]],
    'org-admin' => [OrganizationRole::Admin, ['viewAny' => true, 'view' => true, 'create' => true, 'update' => true, 'delete' => true, 'restore' => true]],
    'agent' => [OrganizationRole::Agent, ['viewAny' => true, 'view' => true, 'create' => true, 'update' => true, 'delete' => true, 'restore' => true]],
    'property-owner' => [OrganizationRole::PropertyOwner, ['viewAny' => false, 'view' => false, 'create' => false, 'update' => false, 'delete' => false, 'restore' => false]],
    'tenant' => [OrganizationRole::Tenant, ['viewAny' => false, 'view' => false, 'create' => false, 'update' => false, 'delete' => false, 'restore' => false]],
]);

it('never lets an archived tenant be edited or archived again, nor an active one restored', function () {
    expect(inOrganization($this->acme, fn (): array => [
        Gate::forUser($this->owner)->allows('update', $this->archived),
        Gate::forUser($this->owner)->allows('delete', $this->archived),
        Gate::forUser($this->owner)->allows('restore', $this->record),
    ]))->toBe([false, false, false]);
});

it('grants the tenant permissions to the workspace roles, and classes them for the central guards', function () {
    $holders = fn (OrganizationPermission $permission): array => array_values(array_map(
        fn (OrganizationRole $role): string => $role->value,
        array_filter(OrganizationRole::cases(), fn (OrganizationRole $role): bool => in_array($permission, $role->permissions(), true)),
    ));

    expect($holders(OrganizationPermission::ViewTenants))->toBe(['org-owner', 'org-admin', 'agent'])
        ->and($holders(OrganizationPermission::ManageTenants))->toBe(['org-owner', 'org-admin', 'agent'])
        ->and(OrganizationPermission::ViewTenants->allowedWhileSuspended())->toBeTrue()
        ->and(OrganizationPermission::ManageTenants->allowedWhileSuspended())->toBeFalse()
        ->and(OrganizationPermission::ViewTenants->blockedWhileImpersonating())->toBeFalse()
        ->and(OrganizationPermission::ManageTenants->blockedWhileImpersonating())->toBeFalse();
});

it('refuses everything to a member of another organization and to strangers, whose workspace never finds the tenant', function () {
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $outsider = joinAs(User::factory()->create(), $other);
    $stranger = User::factory()->create();

    foreach ([$outsider, $stranger] as $user) {
        expect(array_filter(tenantAbilitiesOf($user, $this->acme, $this->record, $this->archived)))->toBe([]);
    }

    expect(inOrganization($other, fn () => TenantProfile::query()->withTrashed()->find($this->record->id)))->toBeNull()
        ->and(inOrganization($other, fn (): bool => Gate::forUser($outsider)->allows('view', $this->record)))->toBeFalse();
});

it('keeps tenants read-only while the organization is suspended', function () {
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    expect(tenantAbilitiesOf($this->owner, $this->acme->fresh(), $this->record, $this->archived))
        ->toBe(['viewAny' => true, 'view' => true, 'create' => false, 'update' => false, 'delete' => false, 'restore' => false]);
});
