<?php

declare(strict_types=1);

namespace Modules\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment was confirmed (ARCHITECTURE.md § 6.4, § 8 rule 3): plain ids
 * and values, never the model. Sent inside the confirming transaction; a
 * listener that must act only once it is committed implements
 * ShouldHandleEventsAfterCommit. The receipt (P03-10b) and the tenant's
 * notification follow from it.
 *
 * `allocations` lists the bills it settled as ['billId' => …,
 * 'amountKobo' => …]; `creditKobo` went to the tenant's credit.
 * `confirmedByUserId` is null when the system confirmed it (a verified
 * Paystack webhook).
 */
final class PaymentConfirmed
{
    use Dispatchable;

    /**
     * @param  list<array{billId: int, amountKobo: int}>  $allocations
     */
    public function __construct(
        public readonly int $paymentId,
        public readonly int $organizationId,
        public readonly int $tenantProfileId,
        public readonly int $amountKobo,
        public readonly string $method,
        public readonly string $source,
        public readonly ?int $confirmedByUserId,
        public readonly array $allocations,
        public readonly int $creditKobo,
    ) {}
}
