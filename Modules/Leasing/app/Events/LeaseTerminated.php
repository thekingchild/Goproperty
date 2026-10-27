<?php

declare(strict_types=1);

namespace Modules\Leasing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A lease was ended early with a reason (an active lease), or a draft was cancelled (`wasDraft`). The unit is released unless another lease holds it.
 * See LeaseEvent.
 */
final readonly class LeaseTerminated extends LeaseEvent
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
