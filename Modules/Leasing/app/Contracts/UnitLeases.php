<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

/**
 * The leases that hold a unit now (draft or active; a draft is the unit
 * reserved for its next tenant), for other modules that need "who lives
 * here" — maintenance tickets raised by staff for a unit's tenant
 * (P04-01), and one lease by id for a ticket raised from a lease's page
 * (P04-03). Leases removed by an import rollback do not count. Answers for
 * the current organization only; no permission check (the caller checks
 * its own policy). Bound in LeasingServiceProvider.
 */
interface UnitLeases
{
    /**
     * The unit's draft and active leases, active first, then newest start.
     *
     * @return list<LeaseSummary>
     */
    public function live(int $unitId): array;

    /** The lease, whatever its status, or null when there is no such lease here (P04-03: a ticket raised from a lease's page). */
    public function lease(int $leaseId): ?LeaseSummary;
}
