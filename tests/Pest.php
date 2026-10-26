<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\Role;
use App\Models\StaffRole;
use App\Models\StaffStatus;
use App\Models\User;
use Database\Seeders\OrganizationRolesSeeder;
use Database\Seeders\StaffRolesSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run on the application test case with a fresh database.
| Unit tests stay framework-free unless a file opts in.
|
| Drills start real `php artisan` processes, which cannot see rows inside
| a test transaction, so they commit and truncate instead.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->group('drill')
    ->in('Drill');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A platform staff member. Staff must use an authenticator app (P00-10c),
 * so by default the account has 2FA set up and can open console pages.
 * Console access comes from staff roles (P01-07): `super-admin` unless
 * other roles are given ([] for none).
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<string>  $roles
 */
function staffMember(array $attributes = [], bool $withTwoFactor = true, array $roles = ['super-admin']): PlatformStaff
{
    $staff = PlatformStaff::query()->create($attributes + [
        'name' => 'Ada',
        'email' => 'ada@goproperty.ng',
        'password' => 'x-long-password-1',
        'status' => StaffStatus::Active,
    ]);

    if ($withTwoFactor) {
        $staff->saveAppAuthenticationSecret(app(Google2FA::class)->generateSecretKey());
    }

    if ($roles !== []) {
        seedStaffRoles();
        $staff->assignRole(...$roles);
    }

    return $staff->fresh() ?? $staff;
}

/**
 * Another staff member with an email made from the name, e.g.
 * staffNamed('Bola', ['finance-admin']) → bola@goproperty.ng.
 *
 * @param  list<string>  $roles
 */
function staffNamed(string $name, array $roles = [], bool $withTwoFactor = true): PlatformStaff
{
    return staffMember(['name' => $name, 'email' => strtolower($name).'@goproperty.ng'], $withTwoFactor, $roles);
}

/** The staff permission catalogue and default staff roles (StaffRolesSeeder), once per test. */
function seedStaffRoles(): void
{
    if (! StaffRole::query()->where('name', 'super-admin')->exists()) {
        test()->seed(StaffRolesSeeder::class);
    }
}

/**
 * Makes the user a member of the organization with a role there (org-owner
 * unless given), seeding the customer roles first if the test has not.
 * Only owners, administrators and agents open the workspace at /o/{slug}.
 */
function joinAs(User $user, Organization $organization, OrganizationRole $role = OrganizationRole::Owner): User
{
    seedOrganizationRoles();
    $user->joinOrganization($organization, $role);

    return $user;
}

/** The customer roles and permission catalogue (OrganizationRolesSeeder), once per test. */
function seedOrganizationRoles(): void
{
    if (! Role::query()->whereNull('organization_id')->where('name', OrganizationRole::Owner->value)->exists()) {
        test()->seed(OrganizationRolesSeeder::class);
    }
}
