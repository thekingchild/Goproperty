<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A subscription invoice as the customer sees it (P06-02;
 * BILLING_PAYMENTS.md § 3): the plan and the period, the units counted, a
 * line for the base price and one for the extra units (each a month), the
 * subtotal for the period, VAT as its own line, and the total. With the
 * latest proof of a bank transfer sent for it, if any (P06-04).
 */
final readonly class SubscriptionInvoice
{
    /** @param  list<InvoiceLine>  $lines  the month's lines, before the subtotal */
    public function __construct(
        public string $number,
        public string $planName,
        /** "1–31 December 2026" */
        public string $period,
        public int $units,
        public array $lines,
        /** "Subtotal", "Subtotal for 15 of 30 days", "Subtotal for 12 months, priced as 10" */
        public string $subtotalLabel,
        public Money $subtotal,
        /** "VAT (7.5%)" */
        public string $vatLabel,
        public Money $vat,
        public Money $total,
        /** open, paid, void */
        public string $status,
        public string $statusLabel,
        public bool $covered,
        public ?CarbonImmutable $dueDate,
        public ?CarbonImmutable $paidAt,
        public ?CarbonImmutable $issuedAt,
        public ?BankTransferStatus $transfer = null,
    ) {}
}
