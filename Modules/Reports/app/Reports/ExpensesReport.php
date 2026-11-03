<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

use App\Support\Money;

/**
 * Expenses by category over the range (P08-01): expenses not archived, by
 * the day they were paid, the buildings' and the organization's own (no
 * building; only in a report of every building), largest category first.
 */
final readonly class ExpensesReport
{
    /** @param  list<ExpenseCategoryLine>  $categories */
    public function __construct(
        public array $categories,
        public bool $includesOrganization,
    ) {}

    public function total(): Money
    {
        return Money::sum(array_map(static fn (ExpenseCategoryLine $line): Money => $line->total(), $this->categories));
    }

    public function count(): int
    {
        return array_sum(array_map(static fn (ExpenseCategoryLine $line): int => $line->count, $this->categories));
    }
}
