<?php

declare(strict_types=1);

namespace Modules\Leasing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An active lease was renewed: its renewal (`renewalLeaseId`, a new lease
 * referencing it) was activated, and this lease (`leaseId`) is now
 * `renewed`. It still runs to its own end date; the unit stays occupied.
 * See LeaseEvent.
 */
final readonly class LeaseRenewed extends LeaseEvent
{
    use Dispatchable;

    public function __construct(
        int $organizationId,
        int $leaseId,
        int $propertyId,
        int $unitId,
        int $tenantProfileId,
        ?string $unitStatus,
        public int $renewalLeaseId,
    ) {
        parent::__construct($organizationId, $leaseId, $propertyId, $unitId, $tenantProfileId, $unitStatus);
    }
}
