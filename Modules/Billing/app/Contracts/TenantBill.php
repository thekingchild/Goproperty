<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use App\Documents\DocumentBranding;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A bill as its tenant sees it in the tenant portal (Contracts\TenantBills,
 * P03-11a): plain values, no model. `brand` is the agency's branding as the
 * bill was issued with it (the invoice's snapshot), so each agency's bills
 * keep that agency's logo and colours (UX_DESIGN.md § 2); `property` is
 * "Palm Court — Flat 3B" as issued. `status` is a `bills.status` value
 * (unpaid, partially_paid, paid, overdue, void) with its label and Flux
 * badge colour; `outstanding` is what is still owed now (zero on a void
 * bill).
 */
final readonly class TenantBill
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $tenantProfileId,
        public ?int $leaseId,
        public string $number,
        public string $paymentReference,
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
        public ?CarbonImmutable $voidedAt,
        public ?string $property,
        public DocumentBranding $brand,
    ) {}

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    /** Whether the bill still asks for money (open, with something outstanding). */
    public function asksForMoney(): bool
    {
        return in_array($this->status, ['unpaid', 'partially_paid', 'overdue'], true) && $this->outstanding->isPositive();
    }

    public function isOverdue(): bool
    {
        return $this->status === 'overdue';
    }
}
