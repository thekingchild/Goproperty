<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Listeners;

use Modules\BuildingGroup\Groups\BuildingGroups;
use Modules\BuildingGroup\Groups\InOrganization;
use Modules\Leasing\Events\LeaseActivated;
use Modules\Leasing\Events\LeaseEvent;
use Modules\Leasing\Events\LeaseExpired;
use Modules\Leasing\Events\LeaseImported;
use Modules\Leasing\Events\LeaseImportRolledBack;
use Modules\Leasing\Events\LeaseTerminated;

/**
 * Keeps each building's group members in step with its leases (P07-01):
 * on LeaseActivated, LeaseTerminated, LeaseExpired, LeaseImported and
 * LeaseImportRolledBack, the building's group is synced
 * (BuildingGroups::sync: its tenant members are the tenants with an active
 * lease there). Runs inside the lease's own transaction (the events are
 * sent there), so the membership and the lease change together or not at
 * all. A draft's events change nobody's membership and are skipped.
 */
final readonly class FollowLeases
{
    public function __construct(private BuildingGroups $groups, private InOrganization $organization) {}

    public function handle(LeaseEvent $event): void
    {
        if (($event instanceof LeaseTerminated || $event instanceof LeaseExpired) && $event->wasDraft) {
            return;
        }

        $this->organization->run($event->organizationId, fn () => $this->groups->sync($event->propertyId));
    }

    /** @return list<class-string<LeaseEvent>> */
    public static function events(): array
    {
        return [
            LeaseActivated::class,
            LeaseTerminated::class,
            LeaseExpired::class,
            LeaseImported::class,
            LeaseImportRolledBack::class,
        ];
    }
}
