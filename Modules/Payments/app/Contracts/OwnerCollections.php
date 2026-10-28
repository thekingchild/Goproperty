<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use Carbon\CarbonImmutable;

/**
 * What was collected for bills, by when it counts (the owner statements,
 * P08-05; never the payments tables themselves, ARCHITECTURE.md § 8
 * rule 3):
 *
 * - a confirmed payment's allocations, at the payment's `confirmed_at`
 *   (payments refunded since count as confirmed: undoing a payment is not
 *   built yet, and a refund will count in its own month when it is);
 * - tenant credit applied to a bill (`applied_to_bill` credit entries), at
 *   the time it was applied: credit is the tenant's money held for later,
 *   so it counts once it settles a bill, never when it was paid in (an
 *   overpayment, or credit refunded or handed over, is never counted);
 * - a voided receipt: its payment's allocations again, negative, at the
 *   receipt's `voided_at` (the payment and its allocations themselves do
 *   not change, P03-10b).
 *
 * Every moment is a time recorded when it happened, never a date typed by
 * someone, so nothing lands in a month after that month was settled.
 * Answers for the current organization only (OrganizationContext). Reads
 * only. Bound in PaymentsServiceProvider.
 */
interface OwnerCollections
{
    /**
     * Everything that counts in [$from, $to), oldest first; any bill (the
     * caller keeps its own bills).
     *
     * @return list<CollectedAmount>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array;
}
