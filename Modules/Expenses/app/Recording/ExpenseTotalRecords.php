<?php

declare(strict_types=1);

namespace Modules\Expenses\Recording;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Expenses\Contracts\ExpenseTotal;
use Modules\Expenses\Contracts\ExpenseTotals;
use Modules\Expenses\Enums\ExpenseCategory;
use Modules\Expenses\Models\Expense;

/**
 * The Expenses module's answers to Contracts\ExpenseTotals (P08-01): one
 * grouped query through the organization scope (archived expenses are
 * soft deleted, so left out), on the (organization_id, spent_on) index.
 * Reads only.
 */
final readonly class ExpenseTotalRecords implements ExpenseTotals
{
    public function between(CarbonImmutable $from, CarbonImmutable $to, ?array $propertyIds = null): array
    {
        $rows = Expense::query()
            ->when($propertyIds !== null, static fn (Builder $query) => $query->whereIn('property_id', $propertyIds === [] ? [0] : $propertyIds))
            ->where('spent_on', '>=', $from->toDateString())
            ->where('spent_on', '<=', $to->toDateString())
            ->toBase()
            ->select(['property_id', 'category'])
            ->selectRaw('count(*) as expenses_count, sum(amount_kobo) as amount_total')
            ->groupBy('property_id', 'category')
            ->orderBy('property_id')
            ->orderBy('category')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $category = ExpenseCategory::from((string) $row->category);
            $totals[] = new ExpenseTotal(
                propertyId: $row->property_id === null ? null : (int) $row->property_id,
                category: $category->value,
                categoryLabel: $category->label(),
                count: (int) $row->expenses_count,
                amount: Money::kobo((int) $row->amount_total),
            );
        }

        return $totals;
    }
}
