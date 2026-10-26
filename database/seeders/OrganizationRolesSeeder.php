<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\Role;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The customer roles and their permissions (ARCHITECTURE.md § 5), from the
 * catalogue in code (OrganizationPermission, OrganizationRole). The roles
 * have no organization: every organization shares them, and a user's role
 * is assigned per organization. Idempotent (InstallSeeder): permissions and
 * roles are found by name and guard, and each role's permissions are synced
 * to the catalogue.
 */
final class OrganizationRolesSeeder extends Seeder
{
    public function run(OrganizationContext $context, PermissionRegistrar $registrar): void
    {
        $context->outside(function () use ($registrar): void {
            foreach (OrganizationPermission::cases() as $permission) {
                Permission::findOrCreate($permission->value, OrganizationPermission::GUARD);
            }

            // Model events (which flush spatie's cache) may be muted by the
            // calling seeder (WithoutModelEvents).
            $registrar->forgetCachedPermissions();

            foreach (OrganizationRole::cases() as $role) {
                $model = Role::query()->firstOrCreate([
                    'organization_id' => null,
                    'name' => $role->value,
                    'guard_name' => OrganizationPermission::GUARD,
                ]);

                $model->syncPermissions(array_map(static fn (OrganizationPermission $permission): string => $permission->value, $role->permissions()));
            }
        });

        $registrar->forgetCachedPermissions();
    }
}
