<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

/** The owner portal's home section for one agency (P03-15): its frame and the owner's buildings there. */
final readonly class AgencyBuildings
{
    /** @param  list<OwnerBuilding>  $buildings */
    public function __construct(
        public OwnerAgency $agency,
        public array $buildings,
    ) {}
}
