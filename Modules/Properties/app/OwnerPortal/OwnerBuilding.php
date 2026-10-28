<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

/**
 * One of the owner's buildings as the owner portal lists it (P03-15): its
 * name and address, whether the agency still manages it (archived: kept
 * read-only), and its units by status (occupancy). Plain values.
 */
final readonly class OwnerBuilding
{
    /** @param  array<string, int>  $unitsByStatus  `units.status` value => count */
    public function __construct(
        public int $id,
        public string $name,
        public string $address,
        public string $typeLabel,
        public bool $archived,
        public int $unitCount,
        public array $unitsByStatus,
    ) {}

    public function occupied(): int
    {
        return $this->unitsByStatus['occupied'] ?? 0;
    }

    public function vacant(): int
    {
        return $this->unitsByStatus['vacant'] ?? 0;
    }

    /** Reserved, under maintenance or unavailable. */
    public function other(): int
    {
        return $this->unitCount - $this->occupied() - $this->vacant();
    }
}
