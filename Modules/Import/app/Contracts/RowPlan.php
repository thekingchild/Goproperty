<?php

declare(strict_types=1);

namespace Modules\Import\Contracts;

/**
 * What a valid row would do (dry run): the action, the record as it would
 * look (column key => display value, for the preview), and what it adds to
 * the subscription (units) and to the Transaction Tier forecast (expected
 * yearly collections in kobo, DATA_IMPORT.md § 2.3).
 */
final readonly class RowPlan
{
    /** @param  array<string, string|null>  $preview */
    public function __construct(
        public ImportAction $action,
        public array $preview = [],
        public int $units = 0,
        public int $yearlyCollectionsKobo = 0,
        public ?string $reason = null,
    ) {}
}
