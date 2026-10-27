<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use LogicException;

/**
 * Which leases have rent to bill, for Billing's rent bill generation
 * (P03-04b; ARCHITECTURE.md § 6.4): never the `leases` table itself.
 *
 * A lease is billable while it is active, and also once renewed (its
 * renewal is a new lease: the renewed lease still runs to its end date),
 * as long as its next period starts on or before its end date. Expired,
 * terminated and draft leases bill nothing. Billing generates a period's
 * bill `lead_days` before the period starts (CycleCalculator::billingDate)
 * and then calls advance(), so the lease moves to its next period; advance
 * is idempotent, so a re-run creates nothing twice when Billing keys its
 * bills by `(lease_id, type, period_start)`.
 *
 * Answers for the current organization only (OrganizationContext). Bound
 * in LeasingServiceProvider.
 */
interface LeaseBillingSchedule
{
    /**
     * Billable leases whose next period's bill is due on $date (the period
     * starts on or before $date + $leadDays), by id, after $afterId, at
     * most $limit (page through with the last id).
     *
     * @return list<BillableLease>
     */
    public function due(DateTimeInterface $date, int $leadDays = CycleCalculator::DEFAULT_LEAD_DAYS, int $afterId = 0, int $limit = 500): array;

    /** The lease, if it is billable now (null otherwise). */
    public function find(int $leaseId): ?BillableLease;

    /**
     * Move the lease past the period starting $periodStart (its bill was
     * made): `next_billing_date` becomes the next period's start. Does
     * nothing when the lease is already past that period (a re-run).
     * Returns the lease's next billing date afterwards.
     *
     * @throws LogicException when $periodStart is not the lease's next billing date nor already billed
     */
    public function advance(int $leaseId, DateTimeInterface $periodStart): ?CarbonImmutable;
}
