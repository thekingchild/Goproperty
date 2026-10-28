<?php

declare(strict_types=1);

namespace Modules\Expenses\Contracts;

use Carbon\CarbonImmutable;

/**
 * The expenses an agency charges to buildings' owners (the owner
 * statements, P08-05; never the `expenses` table itself, ARCHITECTURE.md
 * § 8 rule 3): expenses marked "charged to owner" (`charged_to_owner`),
 * not archived, by the day they were paid (`spent_on`, a Lagos date).
 * Answers for the current organization only. Reads only. Bound in
 * ExpensesServiceProvider.
 */
interface OwnerChargedExpenses
{
    /**
     * The charged expenses of these buildings paid between $from and $to
     * (both days included), by date paid.
     *
     * @param  list<int>  $propertyIds
     * @return list<ChargedExpense>
     */
    public function between(array $propertyIds, CarbonImmutable $from, CarbonImmutable $to): array;
}
