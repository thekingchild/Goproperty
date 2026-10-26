<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformStaff;
use App\Platform\Customers\CustomerUser;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Customers → Users, the users lookup (PLATFORM_CONSOLE.md § 1,
 * `customers.view`). Read-only: customers manage their own accounts.
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
