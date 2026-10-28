<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Modules\Leasing\Contracts\BillingCycle;

/**
 * An opening balance to bill (Contracts\OpeningBalanceBills::issue): what
 * the tenant owed for a unit on the as-of date, with the lease it belongs
 * to when there is one (number and cycle printed on the invoice), the
 * agency's description, and the import it came from. Plain values.
 */
final readonly class OpeningBalanceBill
{
    public function __construct(
        public int $tenantProfileId,
        public int $propertyId,
        public int $unitId,
        public CarbonImmutable $asOf,
        public Money $amount,
        public int $importBatchId,
        public ?string $description = null,
        public ?int $leaseId = null,
        public ?string $leaseNumber = null,
        public ?BillingCycle $cycle = null,
    ) {}
}
