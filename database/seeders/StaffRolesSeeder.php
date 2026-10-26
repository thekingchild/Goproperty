<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\StaffRole;
use App\Platform\Staff\DefaultStaffRole;
use App\Platform\Staff\StaffPermission;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The staff permission catalogue and the default staff roles
 * (PLATFORM_CONSOLE.md § 1, § 6), on the `staff` guard with no organization.
 *
 * Idempotent (InstallSeeder), and it never undoes a super-admin's edits:
 * a default role gets its default permissions when the role is created,
 * and later only the permissions that are new to the catalogue (a new
 * feature's permissions reach the roles meant to have them). `super-admin`
 * always holds the whole catalogue and stays protected.
 */
final class StaffRolesSeeder extends Seeder
{
    public function run(OrganizationContext $context, PermissionRegistrar $registrar): void
    {
        $context->outside(function () use ($registrar): void {
            $new = [];

            foreach (StaffPermission::cases() as $permission) {
                $exists = Permission::query()->where('name', $permission->value)->where('guard_name', StaffPermission::GUARD)->exists();

                if (! $exists) {
                    Permission::query()->create(['name' => $permission->value, 'guard_name' => StaffPermission::GUARD]);
                    $new[] = $permission->value;
                }
            }

            // Model events (which flush spatie's cache) may be muted by the
            // calling seeder (WithoutModelEvents).
            $registrar->forgetCachedPermissions();

            foreach (DefaultStaffRole::cases() as $default) {
                $role = StaffRole::query()->where('name', $default->value)->first();
                $created = $role === null;

                if ($role === null) {
                    $role = new StaffRole;
                    $role->forceFill([
                        'name' => $default->value,
                        'description' => $default->description(),
                        'is_default' => true,
                        'is_protected' => $default === DefaultStaffRole::SuperAdmin,
                        'active' => true,
                    ])->save();
                }

                $grants = array_map(static fn (StaffPermission $permission): string => $permission->value, $default->permissions());

                if ($default === DefaultStaffRole::SuperAdmin) {
                    $role->forceFill(['is_default' => true, 'is_protected' => true, 'active' => true])->save();
                    $role->syncPermissions($grants);

                    continue;
                }

                $grants = $created ? $grants : array_values(array_intersect($grants, $new));

                if ($grants !== []) {
                    $role->givePermissionTo($grants);
                }
            }
        });

        $registrar->forgetCachedPermissions();
    }
}
