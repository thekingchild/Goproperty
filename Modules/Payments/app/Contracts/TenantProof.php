<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A proof of payment as its tenant sees it in the tenant portal
 * (Contracts\TenantProofs, P03-11b): plain values. `status` is a
 * ProofStatus value (waiting, accepted, rejected, archived) with its label
 * and Flux badge colour; `rejectionReason` is the reason the agency gave
 * (the tenant is told it too); `receiptId` the receipt of the accepted
 * payment. `byTenant` is false for a proof a member uploaded for them.
 */
final readonly class TenantProof
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $tenantProfileId,
        public ?int $billId,
        public Money $amount,
        public CarbonImmutable $paidOn,
        public string $senderName,
        public string $bankName,
        public ?string $reference,
        public CarbonImmutable $submittedAt,
        public bool $byTenant,
        public string $status,
        public string $statusLabel,
        public string $statusColor,
        public ?string $rejectionReason,
        public ?int $receiptId,
        public bool $hasFile,
    ) {}
}
