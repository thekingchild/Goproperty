<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A bill as the reminder engine sees it (Contracts\RemindableBills, P05-01):
 * plain values, never the model. `type` is the BillType value (`rent`,
 * `utility`, `opening_balance`, `other`); `owed` is what is still unpaid
 * now; `open` says the bill still asks for money (not paid, not void).
 * `unit` and `building` are as issued (`bills.details`); `periodStart` and
 * `periodEnd` are null for a bill without a period (opening balances).
 */
final readonly class RemindableBill
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public string $type,
        public string $typeLabel,
        public ?int $leaseId,
        public int $tenantProfileId,
        public string $number,
        public string $paymentReference,
        public string $description,
        public Money $amount,
        public Money $owed,
        public CarbonImmutable $dueDate,
        public ?CarbonImmutable $periodStart,
        public ?CarbonImmutable $periodEnd,
        public bool $open,
        public bool $imported,
        public ?string $unit,
        public ?string $building,
    ) {}
}
