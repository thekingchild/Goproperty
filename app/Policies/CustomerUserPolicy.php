<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformStaff;
use App\Platform\Customers\CustomerUser;
use App\Platform\Impersonation\ImpersonationMode;
use App\Platform\Impersonation\Impersonations;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Customers → Users, the users lookup (PLATFORM_CONSOLE.md § 1,
 * `customers.view`). Read-only: customers manage their own accounts.
 * Impersonating a user (§ 6) needs `impersonate.read_only`, or
 * `impersonate.write` for write mode (which a second staff member must
 * approve); App\Platform\Impersonation\Impersonations checks again and
 * records every refusal.
 */
final class CustomerUserPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::CustomersView);
    }

    public function view(PlatformStaff $actor, CustomerUser $user): bool
    {
        return $this->viewAny($actor);
    }

    /** Start a support session as this user, in any mode the staff member holds. */
    public function impersonate(PlatformStaff $actor, CustomerUser $user): bool
    {
        return $this->viewAny($actor) && ($this->impersonateReadOnly($actor, $user) || $this->impersonateWrite($actor, $user));
    }

    public function impersonateReadOnly(PlatformStaff $actor, CustomerUser $user): bool
    {
        return $this->viewAny($actor) && app(Impersonations::class)->mayStart($actor, ImpersonationMode::ReadOnly);
    }

    public function impersonateWrite(PlatformStaff $actor, CustomerUser $user): bool
    {
        return $this->viewAny($actor) && app(Impersonations::class)->mayStart($actor, ImpersonationMode::Write);
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, CustomerUser $user): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, CustomerUser $user): bool
    {
        return false;
    }

    public function deleteAny(PlatformStaff $actor): bool
    {
        return false;
    }
}
