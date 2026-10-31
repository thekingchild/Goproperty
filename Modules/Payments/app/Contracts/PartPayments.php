<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

/**
 * How many payments a rent bill has had against the number its lease allows
 * (P03-17; Leasing's Contracts\RentPaymentLimits), for the tenant portal,
 * the public pay page and the bill pages. A tenant's proof that would break
 * the limit is refused by the Payments module itself; a payment the agency
 * records or matches over it is confirmed and flagged. Answers in the
 * current organization. Bound in PaymentsServiceProvider.
 */
interface PartPayments
{
    /** The bill's allowance, or null when no limit applies (not a rent bill, no lease, or no limit set). */
    public function forBill(int $billId): ?PartPaymentAllowance;
}
