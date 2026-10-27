<?php

declare(strict_types=1);

namespace App\Documents\Receipt;

use App\Support\Money;

/**
 * One invoice a payment was applied to (BRANDING_DOCUMENTS.md § 3 item 5):
 * its number, what it was for, the amount applied, and whether this
 * payment settled it (the PAID chip).
 */
final readonly class ReceiptAllocation
{
    public function __construct(
        public string $invoiceNumber,
        public string $description,
        public Money $amount,
        public bool $settled = false,
    ) {}
}
