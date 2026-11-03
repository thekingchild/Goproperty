<?php

declare(strict_types=1);

namespace Modules\Expenses\Contracts;

use App\Support\Money;

/**
 * The expenses of one building (null: the organization's own) in one
 * category (an `expenses.category` value with its label), how many and
 * their total (ExpenseTotals, P08-01): plain values.
 */
final readonly class ExpenseTotal
{
    public function __construct(
        public ?int $propertyId,
        public string $category,
        public string $categoryLabel,
        public int $count,
        public Money $amount,
    ) {}
}
