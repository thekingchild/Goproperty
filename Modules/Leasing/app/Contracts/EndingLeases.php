<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

/**
 * Which active leases end on given days, with where their renewal stands
 * (EndingLease): the reminder engine's lease renewal alerts (P05-01;
 * ARCHITECTURE.md § 6.7, T−60, T−30, T−7 before the last day). Answers
 * for the current organization only; no permission check. Bound in
 * LeasingServiceProvider.
 */
interface EndingLeases
{
    /**
     * Active leases whose last day (`end_date`) is one of these days (Y-m-d),
     * by id, after $afterId, at most $limit.
     *
     * @param  list<string>  $dates
     * @return list<EndingLease>
     */
    public function endingOn(array $dates, int $afterId = 0, int $limit = 500): array;

    /** The lease if it is still active, with its renewal (null otherwise). */
    public function findActive(int $leaseId): ?EndingLease;
}
