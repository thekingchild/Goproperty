<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

/**
 * The leases that hold a unit now (draft or active; a draft is the unit
 * reserved for its next tenant), for other modules that need "who lives
 * here" — maintenance tickets raised by staff for a unit's tenant
 * (P04-01). Leases removed by an import rollback do not count. Answers for
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
}
