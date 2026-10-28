<?php

declare(strict_types=1);

namespace Modules\Expenses\Lists;

use Modules\Expenses\Models\Expense;

/**
 * An expense with what the list shows next to it: its building's and
 * unit's names (live while they are here, else as recorded), who recorded
 * it and how many receipt files it keeps (ExpenseRows, a page or export
 * chunk at a time).
 */
final readonly class ExpenseRow
{
    public function __construct(
        public Expense $expense,
        public ?string $buildingName,
        public ?string $unitLabel,
        public ?string $recordedBy,
        public int $receipts,
    ) {}
}
