<?php

declare(strict_types=1);

namespace Modules\Properties\Contracts;

/**
 * Units by status (UnitDirectory, the building page's occupancy counts).
 * Archived units are not counted.
 */
final readonly class UnitCounts
{
    public function __construct(
        public int $vacant = 0,
        public int $reserved = 0,
        public int $occupied = 0,
        public int $underMaintenance = 0,
        public int $unavailable = 0,
    ) {}

    /** @param  array<string, int>  $byStatus  status value => count */
    public static function fromStatuses(array $byStatus): self
    {
        return new self(
            vacant: $byStatus[UnitDirectory::VACANT] ?? 0,
            reserved: $byStatus[UnitDirectory::RESERVED] ?? 0,
            occupied: $byStatus[UnitDirectory::OCCUPIED] ?? 0,
            underMaintenance: $byStatus[UnitDirectory::UNDER_MAINTENANCE] ?? 0,
            unavailable: $byStatus[UnitDirectory::UNAVAILABLE] ?? 0,
        );
    }

    public function total(): int
    {
        return $this->vacant + $this->reserved + $this->occupied + $this->underMaintenance + $this->unavailable;
    }

    /** Occupied units as a whole percentage of all units, rounded down; 0 with no units. */
    public function occupancyPercent(): int
    {
        $total = $this->total();

        return $total === 0 ? 0 : intdiv($this->occupied * 100, $total);
    }

    /** @return array<string, int> status value => count, every status */
    public function byStatus(): array
    {
        return [
            UnitDirectory::VACANT => $this->vacant,
            UnitDirectory::RESERVED => $this->reserved,
            UnitDirectory::OCCUPIED => $this->occupied,
            UnitDirectory::UNDER_MAINTENANCE => $this->underMaintenance,
            UnitDirectory::UNAVAILABLE => $this->unavailable,
        ];
    }
}
