<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

use App\Support\Money;
use Carbon\CarbonImmutable;

/** An active lease ending soon (P08-01), with where its renewal stands. */
final readonly class RenewalLine
{
    public function __construct(
        public int $leaseId,
        public ?string $contractNumber,
        public string $tenantName,
        public string $buildingName,
        public string $unitLabel,
        public CarbonImmutable $endDate,
        public int $daysLeft,
        public Money $rent,
        public string $cycleLabel,
        public string $renewalState,
        public string $renewalLabel,
    ) {}
}
