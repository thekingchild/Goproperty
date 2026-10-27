<?php

declare(strict_types=1);

namespace Modules\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment waiting for confirmation was rejected, with the reason
 * (ARCHITECTURE.md § 6.4): nothing was allocated. Plain values; sent inside
 * the rejecting transaction. The tenant's notification follows (P03-10b).
 */
final class PaymentRejected
{
    use Dispatchable;

    public function __construct(
        public readonly int $paymentId,
        public readonly int $organizationId,
        public readonly int $tenantProfileId,
        public readonly int $amountKobo,
        public readonly string $method,
        public readonly string $source,
        public readonly int $rejectedByUserId,
        public readonly string $reason,
    ) {}
}
