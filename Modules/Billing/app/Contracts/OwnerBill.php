<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A bill as the owner of its building sees it in the owner portal
 * (Contracts\OwnerBills, P03-15): plain values, no model. Of the tenant
 * only the name as issued (`tenantName`); never a phone number, an email
 * address or the payment reference. `unit` is the unit as issued ("Flat
 * 3B"). `status` is a `bills.status` value (unpaid, partially_paid, paid,
 * overdue, void) with its label and Flux badge colour; `outstanding` is
 * what is still owed now (zero on a void bill). `lines` (label, period,
 * amount) are filled by OwnerBills::find() only.
 */
final readonly class OwnerBill
{
    /** @param  list<array{label: string, period: ?string, amount: string}>  $lines */
    public function __construct(
        public int $id,
        public int $organizationId,
        public ?int $propertyId,
        public ?int $unitId,
        public ?int $leaseId,
        public string $number,
        public string $typeLabel,
        public string $description,
        public string $status,
        public string $statusLabel,
        public string $statusColor,
        public Money $amount,
        public Money $paid,
        public Money $outstanding,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $dueDate,
        public ?CarbonImmutable $periodStart,
        public ?CarbonImmutable $periodEnd,
        public ?string $tenantName,
        public ?string $unit,
        public array $lines = [],
        public string $type = 'rent',
    ) {}

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }
}
