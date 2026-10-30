<?php

declare(strict_types=1);

namespace App\Plans;

use App\Marketing\Plan;
use App\Marketing\PlanCatalogue;
use App\Models\Organization;
use App\Models\OrganizationFeatureOverride;
use App\Models\Subscription;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;

/**
 * What an organization's plan includes (P06-01b; ARCHITECTURE.md § 6.10:
 * plan → per-customer override → default). The plan is its subscription's,
 * with the terms of the version in effect on the first day of the current
 * billing period (today in trial; docs/plans/P06-subtasks.md default 4);
 * an organization with no subscription yet falls back to the plan chosen
 * at signup (`signup_plan_key`), and one with neither is not gated (the
 * wizard has not reached its plan step; default 9). An override in force
 * wins over the plan.
 *
 *     app(Features::class)->enabled(Feature::WhatsApp, $organization)
 *
 * UI hides entry points; PlanGate refuses the routes and Livewire calls;
 * the services check again: all three, always.
 */
final readonly class Features
{
    public function __construct(
        private PlanCatalogue $plans,
        private OrganizationContext $context,
    ) {}

    public function value(Feature $feature, ?Organization $organization = null): FeatureValue
    {
        $organization ??= $this->context->get();
        $override = $this->override($feature, $organization);

        if ($override !== null) {
            return $override;
        }

        $plan = $this->planFor($organization);

        if ($plan === null) {
            return $feature->isLimit() ? FeatureValue::limit(null) : FeatureValue::on();
        }

        return $plan->feature($feature);
    }

    public function enabled(Feature $feature, ?Organization $organization = null): bool
    {
        return $this->value($feature, $organization)->enabled;
    }

    /** The limit (null = unlimited). */
    public function limit(Feature $feature, ?Organization $organization = null): ?int
    {
        return $this->value($feature, $organization)->limit;
    }

    /** The organization's plan with its terms now; null before it has chosen one. */
    public function planFor(Organization $organization): ?Plan
    {
        $subscription = $this->subscription($organization);

        if ($subscription instanceof Subscription) {
            return $this->plans->findById($subscription->plan_id, $subscription->termsDate());
        }

        $key = $organization->signup_plan_key;

        return $key !== null ? $this->plans->find($key) : null;
    }

    public function subscription(Organization $organization): ?Subscription
    {
        return $this->context->run($organization, static fn (): ?Subscription => Subscription::query()->first());
    }

    /** The first offered plan that includes the feature (for "on the Growth plan and above"). */
    public function lowestPlanWith(Feature $feature): ?Plan
    {
        foreach ($this->plans->plans() as $plan) {
            $value = $plan->feature($feature);

            if ($feature->isLimit() ? $value->limit === null : $value->enabled) {
                return $plan;
            }
        }

        return null;
    }

    /** The first offered plan with room for $count (staff users), for an upgrade message. */
    public function lowestPlanAllowing(Feature $feature, int $count): ?Plan
    {
        foreach ($this->plans->plans() as $plan) {
            $limit = $plan->feature($feature)->limit;

            if ($limit === null || $limit >= $count) {
                return $plan;
            }
        }

        return null;
    }

    private function override(Feature $feature, Organization $organization): ?FeatureValue
    {
        $override = $this->context->run($organization, static fn (): ?OrganizationFeatureOverride => OrganizationFeatureOverride::query()
            ->where('feature_key', $feature->value)
            ->where(static fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now()))
            ->first());

        return $override?->value();
    }
}
