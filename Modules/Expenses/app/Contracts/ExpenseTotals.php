<?php

declare(strict_types=1);

namespace Modules\Expenses\Contracts;

use Carbon\CarbonImmutable;

/**
 * What the organization spent, summed for the customer dashboards (P08-01,
 * the Reports module; never the `expenses` table itself, ARCHITECTURE.md
 * § 8 rule 3): expenses not archived, by the day they were paid
 * (`spent_on`, a Lagos date), summed by building and category.
 *
 * `$propertyIds` narrows to those buildings' expenses, leaving out the
 * organization's own (no building); null means every expense, the
 * organization's own included. Answers for the current organization only.
 * Reads only; no permission check. Bound in ExpensesServiceProvider.
 */
interface ExpenseTotals
{
    /**
     * The expenses paid between $from and $to (both days included), one
     * line per building (null: the organization's own) and category.
     *
     * @param  list<int>|null  $propertyIds
     * @return list<ExpenseTotal>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to, ?array $propertyIds = null): array;
}
