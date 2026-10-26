<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationStatus;
use App\Models\PlatformStaff;
use App\Platform\Customers\Customer;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Customers → Organizations (PLATFORM_CONSOLE.md § 1): looking up
 * customers needs `customers.view` (support-agent, operations-manager,
 * compliance-officer, finance-admin, super-admin; never analyst);
 * suspending and reactivating need `customers.suspend` (operations-manager,
 * super-admin). Organizations are never created, edited or deleted here.
 * OrganizationSuspension re-checks.
 */
final class CustomerPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::CustomersView);
    }

    public function view(PlatformStaff $actor, Customer $customer): bool
    {
        return $this->viewAny($actor);
    }

    public function suspend(PlatformStaff $actor, Customer $customer): bool
    {
        return $actor->holdsStaffPermission(P::CustomersView)
            && $actor->holdsStaffPermission(P::CustomersSuspend)
            && $customer->status->canBeSuspended();
    }

    public function reactivate(PlatformStaff $actor, Customer $customer): bool
    {
        return $actor->holdsStaffPermission(P::CustomersView)
            && $actor->holdsStaffPermission(P::CustomersSuspend)
            && $customer->status === OrganizationStatus::Suspended;
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, Customer $customer): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, Customer $customer): bool
    {
        return false;
    }

    public function deleteAny(PlatformStaff $actor): bool
    {
        return false;
    }
}
