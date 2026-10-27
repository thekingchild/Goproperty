<?php

declare(strict_types=1);

namespace Modules\Payments\Payments;

use App\Support\Money;
use Modules\Billing\Contracts\BillBalance;

/**
 * How a payment is (or would be) split: bill id => amount, in the order the
 * bills are settled, and what is left for the tenant's credit. `bills`
 * holds the balances the plan was made from, for the payment page.
 */
final readonly class AllocationPlan
{
    /**
     * @param  array<int, Money>  $allocations  bill id => amount
     * @param  array<int, BillBalance>  $bills  bill id => balance when planned
     */
    public function __construct(
        public array $allocations,
        public Money $credit,
        public array $bills,
    ) {}

    public function allocated(): Money
    {
        return Money::sum($this->allocations);
    }
}
