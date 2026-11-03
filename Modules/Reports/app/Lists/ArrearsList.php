<?php

declare(strict_types=1);

namespace Modules\Reports\Lists;

use App\Exports\ExportCell;
use App\Exports\ExportColumn;
use App\Models\User;
use Modules\Reports\Reports\ArrearsLine;

/** Arrears aging (P08-01): one row per bill owed on or before today, oldest due date first. */
final class ArrearsList extends ReportList
{
    public function key(): string
    {
        return 'report_arrears';
    }

    public function title(): string
    {
        return 'Arrears aging';
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Bill', static fn (ArrearsLine $row): string => $row->bill->number),
            ExportColumn::make('Tenant', static fn (ArrearsLine $row): string => $row->bill->tenantName),
            ExportColumn::make('Building', static fn (ArrearsLine $row): string => $row->buildingName),
            ExportColumn::make('Unit', static fn (ArrearsLine $row): ?string => $row->bill->unit),
            ExportColumn::make('Type', static fn (ArrearsLine $row): string => $row->bill->typeLabel),
            ExportColumn::make('Due date', static fn (ArrearsLine $row) => ExportCell::date($row->bill->dueDate)),
            ExportColumn::make('Days past due', static fn (ArrearsLine $row): int => $row->days),
            ExportColumn::make('Aging', static fn (ArrearsLine $row): string => $row->bucket->label()),
            ExportColumn::make('Amount', static fn (ArrearsLine $row) => $row->bill->amount),
            ExportColumn::make('Paid', static fn (ArrearsLine $row) => $row->bill->paid),
            ExportColumn::make('Owed', static fn (ArrearsLine $row) => $row->bill->outstanding),
        ];
    }

    /** @return list<ArrearsLine> */
    public function rows(User $user, array $filters): array
    {
        [$scope, $cleaned] = $this->scoped($user, $filters);

        return $this->reports->arrears($scope, $cleaned)->lines;
    }
}
