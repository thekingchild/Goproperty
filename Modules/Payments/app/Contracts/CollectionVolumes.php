<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Support\Money;
use DateTimeInterface;

/**
 * What the organization collected from its tenants, for the Transaction
 * Tier monitoring (P06-09; ONBOARDING_COMPLIANCE.md § 5.1, defaults 86 and
 * 91 of docs/plans/P06-subtasks.md): never the payments table itself.
 *
 * A payment counts once confirmed (refunded since: still counted, as
 * reversals are not built) at the moment it was paid (`paid_at`), through
 * any channel. Imported payment history (source `import`) never counts: it
 * was collected before GoProperty. Windows are [$from, $to).
 *
 * Answers for the current organization only (OrganizationContext); no
 * permission check. Bound in PaymentsServiceProvider.
 */
interface CollectionVolumes
{
    public function collectedBetween(DateTimeInterface $from, DateTimeInterface $to): Money;

    /** The largest single payment in the window (zero when none). */
    public function largestBetween(DateTimeInterface $from, DateTimeInterface $to): Money;

    /** The payment, if it is confirmed (or refunded) and counts. */
    public function payment(int $paymentId): ?CollectedPayment;

    /**
     * The tenant's payments in the window by the given methods, oldest first.
     *
     * @param  list<PaymentMethod>  $methods
     * @return list<CollectedPayment>
     */
    public function fromTenant(int $tenantProfileId, DateTimeInterface $from, DateTimeInterface $to, array $methods): array;
}
