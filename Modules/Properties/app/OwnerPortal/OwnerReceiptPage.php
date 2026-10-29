<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use Modules\Payments\Contracts\OwnerReceipt;

/**
 * A receipt for a payment on the owner's bills (P03-16), as an on-screen
 * page built from what was kept at issue, never the issued PDF: its
 * number, date, amount, how the tenant paid, the owner's bills it settled
 * and whether it was voided, with the tenant as the first of those bills
 * was issued and its building (the way back). No payment reference, no
 * bank details.
 */
final readonly class OwnerReceiptPage
{
    public function __construct(
        public OwnerAgency $agency,
        public OwnerReceipt $receipt,
        public ?string $tenantName,
        public int $buildingId,
        public string $building,
    ) {}
}
