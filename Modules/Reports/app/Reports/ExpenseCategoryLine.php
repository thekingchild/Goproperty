<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

use App\Support\Money;

/** One category of the expenses report (P08-01): spent on buildings, on the organization itself, and both. */
final readonly class ExpenseCategoryLine
{
    public function __construct(
        public string $category,
        public string $label,
        public int $count,
        public Money $buildings,
        public Money $organization,
    ) {}

    public function total(): Money
    {
        return $this->buildings->plus($this->organization);
    }
}
