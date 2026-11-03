<?php

declare(strict_types=1);

namespace Modules\Properties\Buildings;

use Illuminate\Database\Eloquent\Builder;
use Modules\Properties\Contracts\BuildingOccupancy;
use Modules\Properties\Contracts\BuildingUnits;
use Modules\Properties\Contracts\UnitCounts;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;

/**
 * The Properties module's answer to Contracts\BuildingOccupancy (P08-01):
 * the active buildings, then their live units grouped by building and
 * status, through the organization scope. Reads only.
 */
final readonly class BuildingOccupancyRecords implements BuildingOccupancy
{
    public function active(?array $propertyIds = null): array
    {
        if ($propertyIds === []) {
            return [];
        }

        /** @var array<int, string> $buildings */
        $buildings = Property::query()
            ->where('status', PropertyStatus::Active)
            ->when($propertyIds !== null, static fn (Builder $query) => $query->whereKey($propertyIds))
            ->orderBy('name')
            ->orderBy('id')
            ->pluck('name', 'id')
            ->all();

        if ($buildings === []) {
            return [];
        }

        /** @var array<int, array<string, int>> $byStatus */
        $byStatus = [];

        foreach (array_chunk(array_keys($buildings), 1000) as $chunk) {
            $rows = Unit::query()->whereIn('property_id', $chunk)->toBase()
                ->select(['property_id', 'status'])
                ->selectRaw('count(*) as units_count')
                ->groupBy('property_id', 'status')
                ->get();

            foreach ($rows as $row) {
                $byStatus[(int) $row->property_id][(string) $row->status] = (int) $row->units_count;
            }
        }

        $lines = [];

        foreach ($buildings as $id => $name) {
            $lines[] = new BuildingUnits((int) $id, (string) $name, UnitCounts::fromStatuses($byStatus[$id] ?? []));
        }

        return $lines;
    }
}
