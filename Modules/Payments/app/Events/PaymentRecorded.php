<?php

declare(strict_types=1);

namespace Modules\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment was recorded and waits for confirmation (P03-10b;
 * ARCHITECTURE.md § 6.4, § 8 rule 3): plain values, never the model. Sent
 * inside the recording transaction. Not sent for a payment recorded and
 * confirmed in the same transaction (it never waited: PaymentConfirmed
 * follows), so the tenant is told once per transition.
 * `recordedByUserId` is null when the system recorded it.
 */
final class PaymentRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly int $paymentId,
        public readonly int $organizationId,
        public readonly int $tenantProfileId,
        public readonly int $amountKobo,
        public readonly string $method,
        public readonly string $source,
        public readonly ?int $recordedByUserId,
    ) {}
}
