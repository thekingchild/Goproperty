<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use App\Support\Money;
use Carbon\CarbonImmutable;

/** One line of a bill being issued (Bills\BillDraft); becomes a `bill_items` row. */
final readonly class BillLine
{
    /** @param  array<string, string|int|bool|null>  $meta */
    public function __construct(
        public string $label,
        public Money $amount,
        public ?string $note = null,
        public int $quantity = 1,
        public ?CarbonImmutable $periodStart = null,
        public ?CarbonImmutable $periodEnd = null,
        public array $meta = [],
    ) {}
}
