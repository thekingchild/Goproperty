<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use App\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * An organization's monthly GoProperty bill, as it stands and with more
 * units (BILLING_PAYMENTS.md § 1.4, § 7.3): for the management handover's
 * accept screen, which shows the receiving organization's bill before and
 * after the handed-over units and refuses an acceptance when the amount
 * changed while the screen was open. The plan is the organization's
 * subscription's at its terms on the next billing day (P06-01b, P06-06),
 * else the signup choice, else the cheapest for the units; the bill's
 * `next` is the invoice the next metering run issues for those units.
 * Bound in SaasBillingServiceProvider.
 */
interface SubscriptionQuotes
{
    /** The bill today, or with $extraUnits more units counted. */
    public function for(Organization $organization, int $extraUnits = 0): MonthlyBill;

    /** The cheapest plan's bill for this many units (to suggest a bigger plan when it would be cheaper). */
    public function cheapestFor(int $units): MonthlyBill;

    /**
     * The organization's next billing day, Lagos time (BILLING_PAYMENTS.md
     * § 3): its trial's end, the 1st for monthly subscriptions, the renewal
     * date for yearly ones; without an organization or a subscription, the
     * 1st of next month.
     */
    public function nextBillingDay(?Organization $organization = null): CarbonImmutable;
}
