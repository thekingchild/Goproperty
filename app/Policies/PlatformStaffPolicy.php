<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformStaff;
use App\Models\StaffStatus;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Staff (PLATFORM_CONSOLE.md § 1). Permissions come from the
 * staff member's roles; staff without 2FA hold none (HasStaffRoles).
 * The services re-check every rule; this decides what the console offers.
 */
final class PlatformStaffPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::StaffView);
    }

    public function view(PlatformStaff $actor, PlatformStaff $staff): bool
    {
        return $actor->holdsStaffPermission(P::StaffView);
    }

    /** Inviting is the only way to add staff. */
    public function create(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::StaffInvite);
    }

    public function update(PlatformStaff $actor, PlatformStaff $staff): bool
    {
        return $actor->holdsStaffPermission(P::StaffManage);
    }

    public function resendInvitation(PlatformStaff $actor, PlatformStaff $staff): bool
    {
        return $actor->holdsStaffPermission(P::StaffInvite)
            && $staff->status === StaffStatus::Invited
            && $staff->invitation_accepted_at === null;
    }

    public function deactivate(PlatformStaff $actor, PlatformStaff $staff): bool
    {
        return $actor->holdsStaffPermission(P::StaffManage)
            && ! $actor->is($staff)
            && $staff->status !== StaffStatus::Deactivated;
    }

    public function reactivate(PlatformStaff $actor, PlatformStaff $staff): bool
    {
        return $actor->holdsStaffPermission(P::StaffManage) && $staff->status === StaffStatus::Deactivated;
    }

    /** Granting and removing roles (two-person rules apply in StaffRoles). */
    public function manageRoles(PlatformStaff $actor, PlatformStaff $staff): bool
    {
        return $actor->holdsStaffPermission(P::RolesManage) && $staff->status !== StaffStatus::Deactivated;
    }

    /** Staff rows are never deleted (AGENT.md § 3 rule 7): deactivate instead. */
    public function delete(PlatformStaff $actor, PlatformStaff $staff): bool
    {
        return false;
    }

    public function deleteAny(PlatformStaff $actor): bool
    {
        return false;
    }
}
