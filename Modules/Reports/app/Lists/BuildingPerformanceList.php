<?php

declare(strict_types=1);

namespace Modules\Reports\Lists;

use App\Exports\ExportColumn;
use App\Models\User;
use Modules\Reports\Reports\BuildingLine;
use Modules\Reports\Reports\Rate;

/** Per-building performance (P08-01): one row per building, then the money linked to none. */
final class BuildingPerformanceList extends ReportList
{
    public function key(): string
    {
        return 'report_buildings';
    }

    public function title(): string
    {
        return 'Building performance';
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Building', static fn (BuildingLine $row): string => $row->name),
            ExportColumn::make('Units', static fn (BuildingLine $row): int => $row->units->total()),
            ExportColumn::make('Occupied', static fn (BuildingLine $row): int => $row->units->occupied),
            ExportColumn::make('Occupancy (%)', static fn (BuildingLine $row): int => $row->units->occupancyPercent()),
            ExportColumn::make('Billed', static fn (BuildingLine $row) => $row->flows->billedTotal()),
            ExportColumn::make('Collected', static fn (BuildingLine $row) => $row->flows->collectedTotal()),
            ExportColumn::make('Collection rate', static fn (BuildingLine $row): string => Rate::labelOf($row->flows->rate())),
            ExportColumn::make('Owed past due (today)', static fn (BuildingLine $row) => $row->arrears),
            ExportColumn::make('Expenses', static fn (BuildingLine $row) => $row->expenses),
            ExportColumn::make('Collected less expenses', static fn (BuildingLine $row) => $row->net()),
        ];
    }

    /** @return list<BuildingLine> */
    public function rows(User $user, array $filters): array
    {
        [$scope, $cleaned] = $this->scoped($user, $filters);

        return $this->reports->buildings($scope, $cleaned)->lines;
    }
}
