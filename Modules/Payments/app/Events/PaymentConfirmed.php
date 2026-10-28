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
 * Paystack webhook, an import).
 *
 * Imported payment history (P03-13b, `source` = `import`): a payment from
 * before GoProperty, confirmed with no allocation and no credit. Imports
 * never contact tenants (DATA_IMPORT.md § 3): listeners that tell the
 * tenant skip it, and `issueReceipt` is false unless the agency ticked
 * "Issue receipt" on its row (then the receipt is issued, still without a
 * notice). Every other confirmation issues its receipt.
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
        public readonly bool $issueReceipt = true,
    ) {}
}
