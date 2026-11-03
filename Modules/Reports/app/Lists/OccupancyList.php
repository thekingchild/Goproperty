<?php

declare(strict_types=1);

namespace Modules\Reports\Lists;

use App\Exports\ExportColumn;
use App\Models\User;
use Modules\Properties\Contracts\BuildingUnits;

/** The occupancy report (P08-01): one row per active building, units by status now. */
final class OccupancyList extends ReportList
{
    public function key(): string
    {
        return 'report_occupancy';
    }

    public function title(): string
    {
        return 'Occupancy';
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Building', static fn (BuildingUnits $row): string => $row->name),
            ExportColumn::make('Units', static fn (BuildingUnits $row): int => $row->units->total()),
            ExportColumn::make('Occupied', static fn (BuildingUnits $row): int => $row->units->occupied),
            ExportColumn::make('Vacant', static fn (BuildingUnits $row): int => $row->units->vacant),
            ExportColumn::make('Reserved', static fn (BuildingUnits $row): int => $row->units->reserved),
            ExportColumn::make('Under maintenance', static fn (BuildingUnits $row): int => $row->units->underMaintenance),
            ExportColumn::make('Unavailable', static fn (BuildingUnits $row): int => $row->units->unavailable),
            ExportColumn::make('Occupancy (%)', static fn (BuildingUnits $row): int => $row->units->occupancyPercent()),
        ];
    }

    /** @return list<BuildingUnits> */
    public function rows(User $user, array $filters): array
    {
        [$scope] = $this->scoped($user, $filters);

        return $this->reports->occupancy($scope)->buildings;
    }
}
