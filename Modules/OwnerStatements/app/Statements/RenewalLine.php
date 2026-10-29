<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A lease of the owner's buildings ending in the next 90 days, with where
 * its renewal stands (P08-05). The tenant's name, phone and email (owners
 * always see them since P03-16).
 */
final readonly class RenewalLine
{
    public function __construct(
        public int $leaseId,
        public string $building,
        public string $unit,
        public string $tenantName,
        public ?string $tenantPhone,
        public ?string $tenantEmail,
        public CarbonImmutable $endDate,
        public Money $rent,
        public string $cycleLabel,
        public string $state,
        public string $stateLabel,
        public ?CarbonImmutable $renewalStart,
    ) {}
}
