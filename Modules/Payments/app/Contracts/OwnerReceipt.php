<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A receipt as the owner of the building sees it in the owner portal
 * (OwnerPayments, P03-16): an on-screen page built from what was kept at
 * issue, never the issued PDF. Its number, the day it was issued, the
 * whole amount received, how and when the tenant paid (`method` a
 * PaymentMethod value with its label: bank transfer, cash, POS, card), the
 * bills of the owner's it settled (`lines`, as issued) and its status:
 * `valid` or `voided` (with the day). A void waiting for its second
 * approver is the agency's business: the receipt is valid until voided.
 * Never the payment reference or bank details.
 */
final readonly class OwnerReceipt
{
    /** @param  list<OwnerReceiptLine>  $lines */
    public function __construct(
        public int $id,
        public int $organizationId,
        public string $number,
        public CarbonImmutable $issuedAt,
        public Money $amount,
        public ?CarbonImmutable $paidOn,
        public string $method,
        public string $methodLabel,
        public string $status,
        public string $statusLabel,
        public string $statusColor,
        public ?CarbonImmutable $voidedAt,
        public array $lines,
    ) {}

    public function isVoided(): bool
    {
        return $this->voidedAt !== null;
    }

    /** What the receipt applied to the owner's bills (it may also have paid other bills, or gone to credit). */
    public function appliedHere(): Money
    {
        return Money::sum(array_map(static fn (OwnerReceiptLine $line): Money => $line->amount, $this->lines));
    }
}
