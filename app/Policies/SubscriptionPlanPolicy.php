<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformStaff;
use App\Models\SubscriptionPlan;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Billing → Plans (P06-01a; PLATFORM_CONSOLE.md § 1A: plans,
 * prices, included units, features and SMS credits are `finance-admin`'s,
 * `plans.manage`). App\Platform\Plans\PlanEditor checks again. Plans are
 * never deleted (stop offering them instead).
 */
final class SubscriptionPlanPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::PlansManage);
    }

    public function view(PlatformStaff $actor, SubscriptionPlan $plan): bool
    {
        return $this->viewAny($actor);
    }

    public function create(PlatformStaff $actor): bool
    {
        return $this->viewAny($actor);
    }

    /** Edit details, schedule new terms, cancel a scheduled version. */
    public function update(PlatformStaff $actor, SubscriptionPlan $plan): bool
    {
        return $this->viewAny($actor);
    }

    public function delete(PlatformStaff $actor, SubscriptionPlan $plan): bool
    {
        return false;
    }
}
