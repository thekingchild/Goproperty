<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Contracts;

use Carbon\CarbonImmutable;

/**
 * Whether a day falls in a month the agency has settled with a building's
 * owner (P08-05), for modules whose records count on owner statements:
 * the Expenses module refuses to charge an expense to the owner in a
 * settled month, or to change a charged one there, so the settled figures
 * and the records always agree. Answers for the current organization only.
 * Bound in OwnerStatementsServiceProvider.
 */
interface SettledMonths
{
    /** "January 2027" when the building's owner's month of $day is settled; null otherwise (no owner, not settled). */
    public function settledMonth(int $propertyId, CarbonImmutable $day): ?string;
}
