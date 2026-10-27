<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Transfers;

use App\Marketing\Plan;
use App\Marketing\PlanCatalogue;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Modules\Properties\Contracts\UnitDirectory;

/**
 * Works out an organization's monthly GoProperty bill from the plan
 * catalogue (config/plans.php until P06-01 brings the plans table) and the
 * units the bill counts, read through the Properties module's contract
 * inside that organization's context (ARCHITECTURE.md § 8 rule 3).
 *
 * The plan is the one chosen at signup (`organizations.signup_plan_key`,
 * interim until the subscription exists); an organization that chose none
 * is shown the cheapest plan for its units, as the pricing calculator
 * picks it.
 */
final readonly class MonthlyBills
{
    public function __construct(
        private PlanCatalogue $plans,
        private UnitDirectory $units,
        private OrganizationContext $context,
    ) {}

    public function for(Organization $organization): MonthlyBill
    {
        $units = $this->context->run($organization, fn (): int => $this->units->countBillable());
        $chosen = $this->chosenPlan($organization);
        $plan = $chosen ?? $this->plans->bestFor($units);

        return new MonthlyBill(
            plan: $plan,
            units: $units,
            amount: $plan->priceFor($units),
            vatPercent: $this->plans->vatPercent(),
            planChosen: $chosen !== null,
        );
    }

    private function chosenPlan(Organization $organization): ?Plan
    {
        foreach ($this->plans->plans() as $plan) {
            if ($organization->signup_plan_key !== null && $plan->key === $organization->signup_plan_key) {
                return $plan;
            }
        }

        return null;
    }
}
