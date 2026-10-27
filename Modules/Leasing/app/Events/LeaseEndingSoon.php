<?php

declare(strict_types=1);

namespace Modules\Leasing\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An active lease ends within the organization's notice window
 * (`leasing.ending_soon_days`, default 60) and has no renewal yet:
 * flagged once by leases:expire-and-flag, for the renewal reminders
 * (P05). The unit is unchanged (`unitStatus` null). See LeaseEvent.
 */
final readonly class LeaseEndingSoon extends LeaseEvent
{
    use Dispatchable;

    public function __construct(
        int $organizationId,
        int $leaseId,
        int $propertyId,
        int $unitId,
        int $tenantProfileId,
        public CarbonImmutable $endDate,
    ) {
        parent::__construct($organizationId, $leaseId, $propertyId, $unitId, $tenantProfileId, null);
    }
}
