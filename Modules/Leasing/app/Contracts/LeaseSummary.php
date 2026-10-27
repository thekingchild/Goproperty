<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A lease as other modules see it (TenantLeases): plain values, no model.
 * `status` is a `leases.status` value (draft, active, expired, terminated,
 * renewed) with its label; `contractNumber` is null for a draft.
 */
final readonly class LeaseSummary
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $propertyId,
        public int $unitId,
        public int $tenantProfileId,
        public ?string $contractNumber,
        public string $status,
        public string $statusLabel,
        public BillingCycle $cycle,
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public Money $rent,
    ) {}
}
