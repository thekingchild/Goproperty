<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Quotes;

use App\Enums\BillingCycle;
use App\Marketing\PlanCatalogue;
use App\Models\Organization;
use App\Models\Subscription;
use App\Plans\Features;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\SaasBilling\Contracts\MonthlyBill;
use Modules\SaasBilling\Contracts\NextInvoice;
use Modules\SaasBilling\Contracts\SubscriptionQuotes;
use Modules\SaasBilling\Metering\BillingPeriod;
use Modules\SaasBilling\Metering\SubscriptionCharge;

/**
 * Works out an organization's GoProperty bill on its next billing day
 * (P06-06) from the units the bill counts, read through the Properties
 * module's contract inside that organization's context (ARCHITECTURE.md
 * § 8 rule 3), by metering's own rules (Metering\BillingPeriod,
 * Metering\SubscriptionCharge), so the quote is what the next metering run
 * bills for the same units:
 *
 * - with a subscription: its next billing period (the trial's end, the 1st
 *   for monthly, the renewal date for yearly) at its plan's terms in effect
 *   on that period's first day (a scheduled price included);
 * - without one: the signup choice, else the cheapest plan for the units
 *   (as the pricing calculator picks it), for a full month from the 1st of
 *   next month.
 */
final readonly class MonthlyBills implements SubscriptionQuotes
{
    public function __construct(
        private PlanCatalogue $plans,
        private UnitDirectory $units,
        private OrganizationContext $context,
        private Features $features,
    ) {}

    public function for(Organization $organization, int $extraUnits = 0): MonthlyBill
    {
        $units = $this->context->run($organization, fn (): int => $this->units->countBillable()) + max(0, $extraUnits);
        $today = CarbonImmutable::now('Africa/Lagos');
        $subscription = $this->features->subscription($organization);

        if ($subscription instanceof Subscription) {
            [$period, $billingDay] = BillingPeriod::next($subscription, $today, $this->plans->monthsPaidPerYear());
            $chosen = $this->plans->findById($subscription->plan_id, $period->start) ?? $this->features->planFor($organization);
            $cycle = $subscription->billing_cycle;
        } else {
            $billingDay = $this->firstOfNextMonth($today);
            $period = BillingPeriod::starting($billingDay, BillingCycle::Monthly, $this->plans->monthsPaidPerYear());
            $chosen = $this->features->planFor($organization);
            $cycle = BillingCycle::Monthly;
        }

        $plan = $chosen ?? $this->plans->bestFor($units);
        $charge = new SubscriptionCharge($plan, $units, $period, $this->plans->vatBasisPoints());

        return new MonthlyBill(
            plan: $plan,
            units: $units,
            amount: $charge->month(),
            vatPercent: $this->plans->vatPercent(),
            planChosen: $chosen !== null,
            next: new NextInvoice(
                billingDay: $billingDay,
                periodStart: $period->start,
                periodEnd: $period->end,
                cycle: $cycle,
                monthsBilled: $period->monthsBilled,
                daysBilled: $period->daysBilled,
                daysInMonth: $period->daysInMonth,
                subtotal: $charge->subtotal(),
                vat: $charge->vat(),
                total: $charge->total(),
                covered: $this->covered($organization, $subscription),
            ),
        );
    }

    public function cheapestFor(int $units): MonthlyBill
    {
        $plan = $this->plans->bestFor($units);

        return new MonthlyBill($plan, $units, $plan->priceFor($units), $this->plans->vatPercent(), planChosen: false);
    }

    public function nextBillingDay(?Organization $organization = null): CarbonImmutable
    {
        if ($organization instanceof Organization) {
            $next = $this->for($organization)->next;

            if ($next instanceof NextInvoice) {
                return $next->billingDay;
            }
        }

        return $this->firstOfNextMonth(CarbonImmutable::now('Africa/Lagos'));
    }

    private function firstOfNextMonth(CarbonImmutable $today): CarbonImmutable
    {
        return $today->setTimezone('Africa/Lagos')->startOfMonth()->addMonthNoOverflow()->startOfDay();
    }

    /** Platform-covered (BILLING_PAYMENTS.md § 2): the organization, or the payer metering bills. */
    private function covered(Organization $organization, ?Subscription $subscription): bool
    {
        if ($organization->platform_covered) {
            return true;
        }

        if (! $subscription instanceof Subscription || $subscription->payerId() === $organization->id) {
            return false;
        }

        return (bool) Organization::query()->whereKey($subscription->payerId())->value('platform_covered');
    }
}
