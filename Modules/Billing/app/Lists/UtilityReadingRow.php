<?php

declare(strict_types=1);

namespace Modules\Billing\Lists;

use Modules\Billing\Models\UtilityReading;

/** A meter reading as the list and its export show it (Lists\UtilityRows). */
final readonly class UtilityReadingRow
{
    public function __construct(
        public UtilityReading $reading,
        public string $unitLabel,
        public string $buildingName,
        public ?string $recordedBy,
    ) {}
}
