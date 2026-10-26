<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformStaff;
use App\Models\StaffRole;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Staff → Roles (PLATFORM_CONSOLE.md § 1): roles are managed with
 * `roles.manage` (a protected permission, held by super-admins); the
 * `super-admin` role is never edited or deactivated, and no role is deleted.
 */
final class StaffRolePolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::StaffView) || $actor->holdsStaffPermission(P::RolesManage);
    }

    public function view(PlatformStaff $actor, StaffRole $role): bool
    {
        return $this->viewAny($actor);
    }

    public function create(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::RolesManage);
    }

    public function update(PlatformStaff $actor, StaffRole $role): bool
    {
        return $actor->holdsStaffPermission(P::RolesManage) && ! $role->is_protected;
    }

    public function deactivate(PlatformStaff $actor, StaffRole $role): bool
    {
        return $this->update($actor, $role) && $role->active;
    }

    public function reactivate(PlatformStaff $actor, StaffRole $role): bool
    {
        return $this->update($actor, $role) && ! $role->active;
    }

    public function delete(PlatformStaff $actor, StaffRole $role): bool
    {
        return false;
    }

    public function deleteAny(PlatformStaff $actor): bool
    {
        return false;
    }
}
