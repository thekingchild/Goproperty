<?php

declare(strict_types=1);

namespace Modules\Billing\Utilities;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Modules\Leasing\Contracts\BillingCycle;

/**
 * A unit's utility as a Utilities import row gives it (P03-13b), checked by
 * Import\UtilitiesImportTarget: overrides only where they differ from the
 * type's defaults, the date it applies from, the first period to bill
 * (never one that started before the import day) and a metered utility's
 * last reading as the starting reading.
 */
final readonly class ImportedUtilitySetting
{
    public function __construct(
        public int $propertyId,
        public int $unitId,
        public int $utilityTypeId,
        public ?Money $amount,
        public ?BillingCycle $cycle,
        public CarbonImmutable $effectiveFrom,
        public CarbonImmutable $nextBillingDate,
        public ?MeterValue $startingReading,
        public ?string $externalRef,
    ) {}
}
