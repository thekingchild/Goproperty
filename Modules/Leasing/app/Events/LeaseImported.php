<?php

declare(strict_types=1);

namespace Modules\Leasing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A lease came in through an import (P03-13a, LeaseService::importOngoing):
 * created, brought back or updated as an ongoing (active) lease or as
 * history (expired), with its billing set from "paid until"
 * (DATA_IMPORT.md § 3). It is not an activation: no contract is issued, no
 * one is told, nothing is billed for the past. For an active lease
 * `unitStatus` lets the unit (occupied). See LeaseEvent.
 */
final readonly class LeaseImported extends LeaseEvent
{
    use Dispatchable;

    public function __construct(
        int $organizationId,
        int $leaseId,
        int $propertyId,
        int $unitId,
        int $tenantProfileId,
        ?string $unitStatus,
        public bool $active = true,
    ) {
        parent::__construct($organizationId, $leaseId, $propertyId, $unitId, $tenantProfileId, $unitStatus);
    }
}
