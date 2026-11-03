<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A bill as the customer dashboards see it (Contracts\BillReports, P08-01):
 * plain values, no model. `type` is a `bills.type` value (rent, utility,
 * opening_balance, other) and `status` a `bills.status` value (unpaid,
 * partially_paid, paid, overdue, void); `outstanding` = amount − paid, zero
 * on a void bill. `tenantName` and `unit` are as the bill was issued.
 */
final readonly class ReportBill
{
    public function __construct(
        public int $id,
        public ?int $propertyId,
        public ?int $unitId,
        public int $tenantProfileId,
        public string $number,
        public string $type,
        public string $typeLabel,
        public string $status,
        public string $statusLabel,
        public Money $amount,
        public Money $paid,
        public Money $outstanding,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $dueDate,
        public string $tenantName,
        public ?string $unit,
    ) {}

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }
}
