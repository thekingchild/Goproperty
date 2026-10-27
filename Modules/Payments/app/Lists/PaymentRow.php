<?php

declare(strict_types=1);

namespace Modules\Payments\Lists;

use Modules\Payments\Models\Payment;

/**
 * A payment with its tenant's name (read through the Tenants contract a
 * page or chunk at a time: PaymentRows), and for a payment from a proof of
 * payment (P03-09) whether it has one and whether the same file came with
 * another proof in the organization.
 */
final readonly class PaymentRow
{
    public function __construct(
        public Payment $payment,
        public string $tenantName,
        public bool $hasProof = false,
        public bool $sameFileAsAnotherProof = false,
    ) {}
}
