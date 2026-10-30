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
 * subscription's at its current terms (P06-01b), else the cheapest for the
 * units. Bound in SaasBillingServiceProvider.
 */
interface SubscriptionQuotes
{
    /** The bill today, or with $extraUnits more units counted. */
    public function for(Organization $organization, int $extraUnits = 0): MonthlyBill;

    /** The cheapest plan's bill for this many units (to suggest a bigger plan when it would be cheaper). */
    public function cheapestFor(int $units): MonthlyBill;

    /** The next billing day: units are counted on the 1st of each month (BILLING_PAYMENTS.md § 3), Lagos time. */
    public function nextBillingDay(): CarbonImmutable;
}
