<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Support\Money;

/** One bill a receipt settled, as issued on the receipt (OwnerReceipt, P03-16): the bill, its number and description then, the amount applied, and whether that paid it in full. */
final readonly class OwnerReceiptLine
{
    public function __construct(
        public int $billId,
        public string $billNumber,
        public string $description,
        public Money $amount,
        public bool $settled,
    ) {}
}
