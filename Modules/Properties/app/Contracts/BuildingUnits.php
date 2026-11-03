<?php

declare(strict_types=1);

namespace Modules\Properties\Contracts;

/** An active building and its units by status (BuildingOccupancy, P08-01): plain values. */
final readonly class BuildingUnits
{
    public function __construct(
        public int $propertyId,
        public string $name,
        public UnitCounts $units,
    ) {}
}
