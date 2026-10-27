<?php

declare(strict_types=1);

namespace Modules\Collections\Statements;

use Carbon\CarbonImmutable;
use Modules\Collections\Enums\LineDirection;

/** One transaction read from a statement row (StatementRowParser), before it is stored. */
final readonly class ParsedLine
{
    public function __construct(
        public int $rowNumber,
        public CarbonImmutable $date,
        public LineDirection $direction,
        /** Positive kobo. */
        public int $amountKobo,
        public string $narration,
        public ?string $counterparty,
        public ?string $reference,
        /** Signed kobo, or null when the statement has no balance column (or the cell is empty). */
        public ?int $balanceKobo,
    ) {}
}
