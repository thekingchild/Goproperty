<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A bill's money as the bill ledger sees it (Contracts\BillLedger): plain
 * values. `status` is a `bills.status` value (unpaid, partially_paid, paid,
 * overdue, void); `outstanding` = amount − paid, never below zero, and zero
 * on a void bill.
 */
final readonly class BillBalance
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $tenantProfileId,
        public ?int $leaseId,
        public ?int $propertyId,
        public ?int $unitId,
        public string $type,
        public string $number,
        public string $paymentReference,
        public string $status,
        public Money $amount,
        public Money $paid,
        public Money $outstanding,
        public CarbonImmutable $dueDate,
        public ?CarbonImmutable $periodStart,
        public ?CarbonImmutable $periodEnd,
    ) {}

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }
}
