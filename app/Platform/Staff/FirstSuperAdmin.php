<?php

declare(strict_types=1);

namespace App\Platform\Staff;

use App\Models\PlatformStaff;
use Database\Seeders\StaffRolesSeeder;
use Illuminate\Contracts\Container\Container;

/**
 * Makes the installer's first staff account a `super-admin`
 * (INSTALLER.md § 5 step 6, PLATFORM_CONSOLE.md § 1). The command-line
 * install and the web wizard call it from their shared step 6, where the
 * account is created. It only ever works for
 * the first account: once anyone holds `super-admin`, further grants go
 * through the console and its two-person rules. Safe to repeat.
 */
final readonly class FirstSuperAdmin
{
    public function __construct(
        private Container $container,
        private StaffRoles $roles,
    ) {}

    public function grant(PlatformStaff $staff): void
    {
        $role = $this->roles->superAdminRole();

        if ($role === null) {
            // Normally seeded with the install seeders already (step 4).
            $seeder = $this->container->make(StaffRolesSeeder::class);
            $seeder->setContainer($this->container)->__invoke();
            $role = $this->roles->superAdminRole() ?? throw new StaffChangeRefused('The super-admin role could not be created.');
        }

        $first = PlatformStaff::query()->orderBy('id')->value('id');
        $others = PlatformStaff::query()
            ->whereKeyNot($staff->getKey())
            ->whereHas('roles', fn ($query) => $query->whereKey($role->getKey()))
            ->exists();

        if ($first !== $staff->id || $others) {
            throw new StaffChangeRefused('Only the first staff account is made super-admin by the installer.');
        }

        $this->roles->assignWithoutActor($staff, $role, ['reason' => 'installer: first staff account']);
    }
}
