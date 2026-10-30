<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Metering;

use App\Marketing\Plan;
use App\Support\Money;

/**
 * What a billing period costs (P06-02; BILLING_PAYMENTS.md § 1.4): the
 * plan's base price plus each unit over the included ones (the month),
 * times the months paid for a yearly period, or by days for a part month
 * (rounded to the nearest kobo, halves up); VAT on top as its own line,
 * rounded the same way. Kobo only (AGENT.md § 3 rule 2).
 */
final readonly class SubscriptionCharge
{
    public function __construct(
        public Plan $plan,
        /** Every unit counted on the billing day. */
        public int $units,
        public BillingPeriod $period,
        public int $vatBasisPoints,
    ) {}

    public function extraUnits(): int
    {
        return max(0, $this->units - $this->plan->includedUnits);
    }

    /** One month: base + extra units × unit price (a custom plan: its base). */
    public function month(): Money
    {
        return $this->plan->priceFor($this->units);
    }

    public function subtotal(): Money
    {
        $month = $this->month();

        if ($this->period->daysBilled !== null && $this->period->daysInMonth !== null) {
            return $month->times($this->period->daysBilled)->dividedBy($this->period->daysInMonth);
        }

        return $month->times($this->period->monthsBilled);
    }

    public function vat(): Money
    {
        return $this->subtotal()->percentage($this->vatBasisPoints);
    }

    public function total(): Money
    {
        return $this->subtotal()->plus($this->vat());
    }
}
