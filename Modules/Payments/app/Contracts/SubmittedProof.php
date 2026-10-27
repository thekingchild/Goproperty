<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

/**
 * A proof of payment as submitted (Contracts\PaymentProofs): plain values.
 * `duplicateOfProofId` is the earlier proof the same file was uploaded
 * with, in this organization (flagged for the reviewer).
 */
final readonly class SubmittedProof
{
    public function __construct(
        public int $proofId,
        public int $paymentId,
        public ?int $duplicateOfProofId,
    ) {}

    public function isDuplicate(): bool
    {
        return $this->duplicateOfProofId !== null;
    }
}
