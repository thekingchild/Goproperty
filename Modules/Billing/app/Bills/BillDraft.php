<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use Carbon\CarbonImmutable;
use Modules\Billing\Enums\BillableType;
use Modules\Billing\Enums\BillType;
use Modules\Leasing\Contracts\BillingCycle;

/**
 * A bill to issue (Bills\BillIssuer): who pays, for what, when it is due
 * and its lines. The issuer adds the number, the payment reference, the
 * verification code, the branding snapshot, the status and the parties
 * as at issue (Bills\BillDetails: the tenant, building and unit are read
 * through their contracts; the lease's number and cycle come with the
 * draft, since Billing does not read leases).
 */
final readonly class BillDraft
{
    /** @param  list<BillLine>  $lines */
    public function __construct(
        public BillType $type,
        public BillableType $billableType,
        public int $billableId,
        public int $tenantProfileId,
        public string $description,
        public CarbonImmutable $dueDate,
        public array $lines,
        public ?int $leaseId = null,
        public ?int $propertyId = null,
        public ?int $unitId = null,
        public ?CarbonImmutable $periodStart = null,
        public ?CarbonImmutable $periodEnd = null,
        public ?int $createdByUserId = null,
        public ?string $leaseNumber = null,
        public ?BillingCycle $cycle = null,
    ) {}
}
