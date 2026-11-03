<?php

declare(strict_types=1);

namespace Modules\Reports\Lists;

use App\Exports\ExportCell;
use App\Exports\ExportColumn;
use App\Models\User;
use Modules\Reports\Reports\CollectionsMonth;
use Modules\Reports\Reports\Rate;

/** Collections vs billed (P08-01): one row per Lagos month of the range, by kind of bill. */
final class CollectionsList extends ReportList
{
    public function key(): string
    {
        return 'report_collections';
    }

    public function title(): string
    {
        return 'Collections vs billed';
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Month', static fn (CollectionsMonth $row): string => $row->month->label),
            ExportColumn::make('From', static fn (CollectionsMonth $row) => ExportCell::date($row->month->first)),
            ExportColumn::make('To', static fn (CollectionsMonth $row) => ExportCell::date($row->month->last)),
            ExportColumn::make('Billed', static fn (CollectionsMonth $row) => $row->totals->billedTotal()),
            ExportColumn::make('Collected', static fn (CollectionsMonth $row) => $row->totals->collectedTotal()),
            ExportColumn::make('Collection rate', static fn (CollectionsMonth $row): string => Rate::labelOf($row->totals->rate())),
            ExportColumn::make('Rent billed', static fn (CollectionsMonth $row) => $row->totals->billed['rent']),
            ExportColumn::make('Rent collected', static fn (CollectionsMonth $row) => $row->totals->collected['rent']),
            ExportColumn::make('Utilities billed', static fn (CollectionsMonth $row) => $row->totals->billed['utility']),
            ExportColumn::make('Utilities collected', static fn (CollectionsMonth $row) => $row->totals->collected['utility']),
            ExportColumn::make('Other billed', static fn (CollectionsMonth $row) => $row->totals->billed['other']),
            ExportColumn::make('Other collected', static fn (CollectionsMonth $row) => $row->totals->collected['other']),
        ];
    }

    /** @return list<CollectionsMonth> */
    public function rows(User $user, array $filters): array
    {
        [$scope, $cleaned] = $this->scoped($user, $filters);

        return $this->reports->collections($scope, $cleaned)->months;
    }
}
