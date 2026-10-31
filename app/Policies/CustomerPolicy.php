<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationStatus;
use App\Enums\SubscriptionStatus;
use App\Models\PlatformStaff;
use App\Platform\Customers\Customer;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Customers → Organizations (PLATFORM_CONSOLE.md § 1): looking up
 * customers needs `customers.view` (support-agent, operations-manager,
 * compliance-officer, finance-admin, super-admin; never analyst);
 * suspending and reactivating need `customers.suspend` (operations-manager,
 * super-admin); SMS credit adjustments and allowances need `plans.manage`
 * (finance-admin, super-admin; P05-03); the subscription's plan
 * `customers.change_plan`, cancelling and reactivating it `customers.suspend`,
 * feature overrides `customers.override_features` (P06-07a). Organizations
 * are never created, edited or deleted here.
 * OrganizationSuspension, CustomerSubscriptions and FeatureOverrides re-check.
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

    /** SMS credits (P05-03): a manual adjustment and a custom monthly allowance, `plans.manage` (finance-admin). */
    public function manageSmsCredits(PlatformStaff $actor, Customer $customer): bool
    {
        return $actor->holdsStaffPermission(P::CustomersView) && $actor->holdsStaffPermission(P::PlansManage);
    }

    /**
     * Change a customer's plan or billing cycle (P06-07a, default 50):
     * `customers.change_plan` (operations-manager, super-admin), once it has
     * a subscription. CustomerSubscriptions re-checks.
     */
    public function changePlan(PlatformStaff $actor, Customer $customer): bool
    {
        return $actor->holdsStaffPermission(P::CustomersView)
            && $actor->holdsStaffPermission(P::CustomersChangePlan)
            && $customer->subscription !== null;
    }

    /** Cancel a subscription that is not cancelled yet (P06-07a, default 52): `customers.suspend`. */
    public function cancelSubscription(PlatformStaff $actor, Customer $customer): bool
    {
        return $actor->holdsStaffPermission(P::CustomersView)
            && $actor->holdsStaffPermission(P::CustomersSuspend)
            && $customer->subscription !== null
            // The lifecycle's own moves (BILLING_PAYMENTS.md § 2): from trial, active or suspended; not while past due or in grace.
            && $customer->subscription->status->canMoveTo(SubscriptionStatus::Cancelled);
    }

    /** Reactivate a cancelled subscription (P06-07a, default 52): `customers.suspend`. */
    public function reactivateSubscription(PlatformStaff $actor, Customer $customer): bool
    {
        return $actor->holdsStaffPermission(P::CustomersView)
            && $actor->holdsStaffPermission(P::CustomersSuspend)
            && $customer->subscription?->status === SubscriptionStatus::Cancelled;
    }

    /**
     * Grant or withhold plan features outside the plan (P06-07a, default
     * 53): `customers.override_features` (operations-manager, super-admin).
     * App\Platform\Plans\FeatureOverrides re-checks.
     */
    public function overrideFeatures(PlatformStaff $actor, Customer $customer): bool
    {
        return $actor->holdsStaffPermission(P::CustomersView) && $actor->holdsStaffPermission(P::CustomersOverrideFeatures);
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
