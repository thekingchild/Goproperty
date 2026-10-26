<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ImpersonationSession;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Customers → Impersonation log (PLATFORM_CONSOLE.md § 6):
 * reading it needs `impersonation.view_log` (super-admin,
 * operations-manager, compliance-officer by default); ending someone's
 * active session needs a super-admin. Sessions are started from a user's
 * page (CustomerUserPolicy::impersonate) and never edited or deleted.
 */
final class ImpersonationSessionPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::ImpersonationViewLog);
    }

    public function view(PlatformStaff $actor, ImpersonationSession $session): bool
    {
        return $this->viewAny($actor);
    }

    public function end(PlatformStaff $actor, ImpersonationSession $session): bool
    {
        return $this->viewAny($actor) && $actor->isSuperAdmin() && $session->isActive();
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, ImpersonationSession $session): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, ImpersonationSession $session): bool
    {
        return false;
    }

    public function deleteAny(PlatformStaff $actor): bool
    {
        return false;
    }
}
