<?php

declare(strict_types=1);

namespace Modules\Leasing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A lease reached its end date (an active lease, by leases:expire-and-flag), or a draft's hold on its unit ran out (`wasDraft`: a stale reservation released). The unit is released unless another lease holds it.
 * See LeaseEvent.
 */
final readonly class LeaseExpired extends LeaseEvent
{
    use Dispatchable;

    public function __construct(
        int $organizationId,
        int $leaseId,
        int $propertyId,
        int $unitId,
        int $tenantProfileId,
        ?string $unitStatus,
        public bool $wasDraft = false,
    ) {
        parent::__construct($organizationId, $leaseId, $propertyId, $unitId, $tenantProfileId, $unitStatus);
    }
}
