<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

/**
 * Which bills the reminder engine reminds tenants about (P05-01;
 * ARCHITECTURE.md § 6.7): never the `bills` table itself. Answers for the
 * current organization only (OrganizationContext); no permission check
 * (the engine is the scheduler, not a member). Bound in
 * BillingServiceProvider.
 */
interface RemindableBills
{
    /**
     * The open bills (unpaid, partly paid or overdue, something still
     * owed) due on one of these days (Y-m-d), by id, after $afterId, at
     * most $limit (page through with the last id).
     *
     * @param  list<string>  $dates
     * @return list<RemindableBill>
     */
    public function dueOn(array $dates, int $afterId = 0, int $limit = 500): array;

    /** The bill as it is now (paid and void ones too), or null when there is no such bill here. */
    public function find(int $billId): ?RemindableBill;

    /** The lease's rent bill for the period starting on $periodStart (Y-m-d), if it was issued. */
    public function rentFor(int $leaseId, string $periodStart): ?RemindableBill;
}
