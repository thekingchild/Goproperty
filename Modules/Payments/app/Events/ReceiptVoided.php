<?php

declare(strict_types=1);

namespace Modules\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A receipt was voided (P03-10b; BRANDING_DOCUMENTS.md § 3): its void was
 * approved by a second owner or administrator, or made alone in an
 * organization with a single one (`singleApprover`). Plain values, sent
 * inside the voiding transaction. The payment itself is unchanged.
 */
final class ReceiptVoided
{
    use Dispatchable;

    public function __construct(
        public readonly int $receiptId,
        public readonly int $paymentId,
        public readonly int $organizationId,
        public readonly int $tenantProfileId,
        public readonly string $number,
        public readonly int $amountKobo,
        public readonly string $reason,
        public readonly int $requestedByUserId,
        public readonly int $approvedByUserId,
        public readonly bool $singleApprover,
    ) {}
}
