<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use Carbon\CarbonImmutable;
use Modules\Billing\Enums\BillableType;
use Modules\Billing\Enums\BillType;

/**
 * A bill to issue (Bills\BillIssuer): who pays, for what, when it is due
 * and its lines. The issuer adds the number, the payment reference, the
 * verification code, the branding snapshot and the status.
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
    ) {}
}
