<?php

declare(strict_types=1);

namespace Modules\Expenses\Recording;

use Carbon\CarbonImmutable;
use Modules\Expenses\Contracts\ChargedExpense;
use Modules\Expenses\Contracts\OwnerChargedExpenses;
use Modules\Expenses\Models\Expense;

/** The Expenses module's answers to Contracts\OwnerChargedExpenses (P08-05). Reads only. */
final readonly class OwnerChargedExpenseRecords implements OwnerChargedExpenses
{
    public function between(array $propertyIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($propertyIds === []) {
            return [];
        }

        return array_values(Expense::query()
            ->whereIn('property_id', $propertyIds)
            ->where('charged_to_owner', true)
            ->where('spent_on', '>=', $from->toDateString())
            ->where('spent_on', '<=', $to->toDateString())
            ->orderBy('spent_on')
            ->orderBy('id')
            ->get()
            ->map(static fn (Expense $expense): ChargedExpense => new ChargedExpense(
                id: $expense->id,
                propertyId: (int) $expense->property_id,
                unitLabel: $expense->unit_label,
                categoryLabel: $expense->category->label(),
                description: $expense->description,
                amount: $expense->amount_kobo,
                spentOn: $expense->spent_on,
            ))
            ->all());
    }
}
