<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use App\Marketing\Plan;
use App\Support\Money;

/**
 * What an organization's GoProperty bill comes to each month
 * (BILLING_PAYMENTS.md § 1.4): its plan's base price plus each unit over
 * the included ones, before VAT. Shown on a management handover's accept
 * screen, before and after the handed-over units, and recorded when the
 * handover is accepted (§ 7.3).
 */
final readonly class MonthlyBill
{
    public function __construct(
        public Plan $plan,
        /** Units the bill counts (Properties' UnitDirectory::countBillable(), plus any units added for a quote). */
        public int $units,
        /** Before VAT. */
        public Money $amount,
        public string $vatPercent,
        /** False when the organization chose no plan yet and the cheapest one for its units is shown. */
        public bool $planChosen,
    ) {}

    /** Units over the plan's included ones, which cost extra. */
    public function extraUnits(): int
    {
        return max(0, $this->units - $this->plan->includedUnits);
    }
}
