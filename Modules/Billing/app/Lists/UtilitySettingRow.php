<?php

declare(strict_types=1);

namespace Modules\Billing\Lists;

use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;

/** A unit's utility as the list and its export show it (Lists\UtilityRows). */
final readonly class UtilitySettingRow
{
    public function __construct(
        public UnitUtilitySetting $setting,
        public string $unitLabel,
        public string $buildingName,
        public bool $unitArchived,
        public ?UtilityReading $lastReading,
    ) {}
}
