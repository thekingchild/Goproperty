<?php

declare(strict_types=1);

namespace Modules\Properties\Units;

use Modules\Leasing\Events\LeaseEvent;
use Modules\Properties\Enums\UnitStatus;

/**
 * Keeps `units.status` in step with leases (ARCHITECTURE.md § 6.2: an
 * allocation is a lease; occupancy is kept on the unit for fast lists):
 * listens to the Leasing module's lease events (LeaseDrafted,
 * LeaseActivated, LeaseRenewed, LeaseTerminated, LeaseExpired, and for
 * imported leases LeaseImported and LeaseImportRolledBack) and sets
 * the status each one carries through the units writer, audited with the
 * event as the reason. Runs inside the lease's own transaction (the events
 * are sent there), so the unit and the lease change together or not at
 * all. Leasing decides the status with the unit's row locked; nothing
 * happens when it says the unit stays as it is.
 */
final readonly class FollowLeases
{
    public function __construct(private Units $units) {}

    public function handle(LeaseEvent $event): void
    {
        if ($event->unitStatus === null) {
            return;
        }

        $status = UnitStatus::from($event->unitStatus);
        $this->units->setStatus($event->unitId, $status, self::reason($event));
    }

    /** "lease_activated", "lease_expired"… with the lease id. */
    private static function reason(LeaseEvent $event): string
    {
        $name = (string) preg_replace('/(?<!^)[A-Z]/', '_$0', class_basename($event));

        return mb_strtolower($name).':'.$event->leaseId;
    }
}
