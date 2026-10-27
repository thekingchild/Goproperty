<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Documents\Receipt\ReceiptDocument;

/**
 * A receipt as its tenant sees it in the tenant portal
 * (Contracts\TenantReceipts, P03-11b): plain values, no model. `document`
 * is the receipt as printed (Core's ReceiptDocument, built from what was
 * kept at issue: the agency's branding, number, amount, payment, the bills
 * it paid), with the void date and reason once voided. `billIds` are the
 * bills it was applied to. A void that waits for its second approver is
 * the agency's business: the receipt stays valid until voided.
 */
final readonly class TenantReceipt
{
    /** @param  list<int>  $billIds */
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $tenantProfileId,
        public ReceiptDocument $document,
        public bool $voided,
        public string $verifyUrl,
        public array $billIds,
    ) {}

    public function number(): string
    {
        return $this->document->number;
    }
}
