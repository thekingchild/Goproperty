<?php

declare(strict_types=1);

namespace Modules\Expenses\Lists;

use App\Models\User;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Models\ExpenseReceipt;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;

/**
 * The names and counts next to a batch of expenses, with one lookup each
 * per batch (no query per expense, ARCHITECTURE.md § 13): buildings and
 * units through the Properties contracts (never their tables, § 8 rule
 * 3), the recording members, and the receipt files kept (archived ones
 * not counted). A building or unit no longer found here (archived unit, a
 * building handed over, P03-14) shows the name recorded with the expense.
 */
final readonly class ExpenseRows
{
    public function __construct(
        private PropertyDirectory $buildings,
        private UnitDirectory $units,
    ) {}

    /**
     * @param  iterable<Expense>  $expenses
     * @return list<ExpenseRow>
     */
    public function rows(iterable $expenses): array
    {
        $expenses = is_array($expenses) ? array_values($expenses) : iterator_to_array($expenses, false);

        if ($expenses === []) {
            return [];
        }

        $buildings = $this->buildings->findMany(self::ids(array_map(static fn (Expense $expense): ?int => $expense->property_id, $expenses)));
        $units = $this->units->findMany(self::ids(array_map(static fn (Expense $expense): ?int => $expense->unit_id, $expenses)));
        $members = User::query()->whereKey(self::ids(array_map(static fn (Expense $expense): ?int => $expense->recorded_by_user_id, $expenses)))->pluck('name', 'id')->all();
        $receipts = ExpenseReceipt::query()
            ->whereIn('expense_id', array_map(static fn (Expense $expense): int => $expense->id, $expenses))
            ->whereNull('archived_at')
            ->selectRaw('expense_id, count(*) as kept')
            ->groupBy('expense_id')
            ->pluck('kept', 'expense_id')
            ->all();

        return array_map(static fn (Expense $expense): ExpenseRow => new ExpenseRow(
            expense: $expense,
            buildingName: $expense->property_id === null ? null : ($buildings[$expense->property_id]->name ?? $expense->property_name),
            unitLabel: $expense->unit_id === null ? null : ($units[$expense->unit_id]->label ?? $expense->unit_label),
            recordedBy: $expense->recorded_by_user_id === null ? null : ($members[$expense->recorded_by_user_id] ?? null),
            receipts: (int) ($receipts[$expense->id] ?? 0),
        ), $expenses);
    }

    /**
     * @param  list<int|null>  $ids
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, static fn (?int $id): bool => $id !== null)));
    }
}
