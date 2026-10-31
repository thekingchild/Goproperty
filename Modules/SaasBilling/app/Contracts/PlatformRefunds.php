<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use App\Models\PlatformStaff;
use Modules\SaasBilling\Enums\RefundStatus;

/**
 * Refunds of payments into GoProperty's own account, for the console
 * (P06-07b; BILLING_PAYMENTS.md § 8; docs/plans/P06-subtasks.md defaults
 * 57–59). A confirmed or held payment is refunded in whole or in part,
 * with a reason: a Paystack payment through Paystack's refund API, a bank
 * transfer by GoProperty's own transfer, recorded with its reference.
 *
 * Above the configured amount (`billing.refund_approval_above_kobo`,
 * counting the payment's earlier refunds) a refund waits for a second staff
 * member with `refunds.approve` (App\Platform\Approvals, action `refund`):
 * requestApproval() keeps the amount back, approve() runs it, notApproved()
 * lets it go. At or below it, refundNow() runs it at once. Requesting needs
 * `refunds.create`, approving `refunds.approve`; checked here again
 * whatever the caller did. Each run is one audit entry with the staff
 * member as causer; the owners are emailed after the commit.
 *
 * Refunding the whole payment that paid a subscription invoice opens the
 * invoice again, due 7 days later; a part refund leaves it paid.
 */
interface PlatformRefunds
{
    /** @throws RefundRefused when there is no such payment */
    public function refundable(int $paymentId): RefundablePayment;

    /** Whether refunding $amountKobo more of the payment needs a second staff member's approval. */
    public function needsApproval(int $paymentId, int $amountKobo): bool;

    /** The amount above which refunds wait for approval, in kobo. */
    public function approvalAboveKobo(): int;

    /**
     * Refunds at once; refused when it needs approval. Returns the refund's id.
     *
     * @throws RefundRefused
     */
    public function refundNow(PlatformStaff $staff, int $paymentId, int $amountKobo, string $reason, ?string $bankReference = null): int;

    /**
     * Opens a refund waiting for approval (its amount is kept back). The
     * caller opens the approval request and attaches it, in the same
     * transaction. Returns the refund's id.
     *
     * @throws RefundRefused
     */
    public function requestApproval(PlatformStaff $staff, int $paymentId, int $amountKobo, string $reason, ?string $bankReference = null): int;

    /** @throws RefundRefused */
    public function attachApproval(int $refundId, int $approvalRequestId): void;

    /**
     * Runs a refund its approval request allowed (inside the approval's
     * transaction: a refusal leaves the request pending).
     *
     * @throws RefundRefused
     */
    public function approve(PlatformStaff $approver, int $refundId, int $approvalRequestId): void;

    /** The approval was rejected or expired: nothing is refunded and the amount is free again. */
    public function notApproved(int $refundId): void;

    /**
     * Asks Paystack how a refund being processed stands and records it.
     *
     * @throws RefundRefused
     */
    public function check(int $refundId, ?PlatformStaff $staff = null): RefundStatus;

    /**
     * Paystack sent a refund event for the payment with this reference: its
     * refunds being processed are checked (never trusting the event itself).
     * Returns how many changed.
     */
    public function checkPayment(string $paystackReference): int;
}
