<?php

declare(strict_types=1);

namespace Modules\Reports\Lists;

use App\Exports\ExportColumn;
use App\Models\User;
use Modules\Reports\Reports\ExpenseCategoryLine;

/** Expenses by category (P08-01): one row per category with expenses in the range, largest first. */
final class ExpensesList extends ReportList
{
    public function key(): string
    {
        return 'report_expenses';
    }

    public function title(): string
    {
        return 'Expenses by category';
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Category', static fn (ExpenseCategoryLine $row): string => $row->label),
            ExportColumn::make('Expenses', static fn (ExpenseCategoryLine $row): int => $row->count),
            ExportColumn::make('On buildings', static fn (ExpenseCategoryLine $row) => $row->buildings),
            ExportColumn::make('Organization\'s own', static fn (ExpenseCategoryLine $row) => $row->organization),
            ExportColumn::make('Total', static fn (ExpenseCategoryLine $row) => $row->total()),
        ];
    }

    /** @return list<ExpenseCategoryLine> */
    public function rows(User $user, array $filters): array
    {
        [$scope, $cleaned] = $this->scoped($user, $filters);

        return $this->reports->expenses($scope, $cleaned)->categories;
    }
}
