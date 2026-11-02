<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Listeners;

use Modules\BuildingGroup\Groups\BuildingGroups;
use Modules\BuildingGroup\Groups\InOrganization;
use Modules\Properties\Events\PropertyCreated;

/** A new building gets its group at once (P07-01: "auto-created"), after the building's transaction commits. */
final readonly class OpenBuildingGroup
{
    public function __construct(private BuildingGroups $groups, private InOrganization $organization) {}

    public function handle(PropertyCreated $event): void
    {
        $this->organization->run($event->organizationId, fn () => $this->groups->sync($event->propertyId));
    }
}
