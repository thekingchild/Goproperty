<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformStaff;
use App\Platform\Handovers\HandoverOverview;
use App\Platform\Staff\StaffPermission as P;

/**
 * Management handovers in the console (BILLING_PAYMENTS.md § 7.7 rule 3):
 * support sees pending and ending ones, with `customers.view` like the
 * customers list they belong to. Nothing is changed from the console.
 */
final class HandoverOverviewPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::CustomersView);
    }

    public function view(PlatformStaff $actor, HandoverOverview $handover): bool
    {
        return $this->viewAny($actor);
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, HandoverOverview $handover): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, HandoverOverview $handover): bool
    {
        return false;
    }

    public function deleteAny(PlatformStaff $actor): bool
    {
        return false;
    }
}
