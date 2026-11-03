<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

/**
 * Collections vs billed over the range (P08-01; docs/plans/P08-subtasks.md):
 *
 * - billed: bills issued in the range (`issued_at`), void bills left out;
 * - collected: money that settled bills, counted as the owner statements
 *   count it (Payments' OwnerCollections, BILLING_PAYMENTS.md § 7.9): a
 *   confirmed payment's share of bills when it was confirmed, tenant
 *   credit when it was used for a bill, a voided receipt negative when it
 *   was voided. An overpayment held as credit is never counted until it
 *   settles a bill, so credit refunded to a tenant is netted out.
 *
 * Collected money may be for bills issued before the range (arrears), so
 * the rate can pass 100%.
 */
final readonly class CollectionsReport
{
    /** @param  list<CollectionsMonth>  $months */
    public function __construct(
        public array $months,
        public FlowTotals $totals,
    ) {}
}
