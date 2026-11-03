<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

use Modules\Properties\Contracts\BuildingUnits;
use Modules\Properties\Contracts\UnitCounts;

/**
 * Occupancy now (P08-01): each active building's units by status and the
 * totals; occupancy is occupied units over all units, rounded down
 * (UnitCounts::occupancyPercent, as on the building page).
 */
final readonly class OccupancyReport
{
    /** @param  list<BuildingUnits>  $buildings */
    public function __construct(
        public array $buildings,
        public UnitCounts $totals,
    ) {}
}
