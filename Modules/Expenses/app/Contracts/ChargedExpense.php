<?php

declare(strict_types=1);

namespace Modules\Expenses\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * An expense charged to its building's owner (OwnerChargedExpenses,
 * P08-05): plain values, no model. The unit label is as recorded.
 */
final readonly class ChargedExpense
{
    public function __construct(
        public int $id,
        public int $propertyId,
        public ?string $unitLabel,
        public string $categoryLabel,
        public string $description,
        public Money $amount,
        public CarbonImmutable $spentOn,
    ) {}
}
