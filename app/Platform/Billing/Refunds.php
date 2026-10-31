<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Models\PlatformStaff;
use App\Platform\Approvals\ApprovalAction;
use App\Platform\Approvals\ApprovalService;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\SaasBilling\Contracts\InvoiceEmailRefused;
use Modules\SaasBilling\Contracts\InvoiceEmails;
use Modules\SaasBilling\Contracts\PlatformRefunds;
use Modules\SaasBilling\Contracts\RefundablePayment;
use Modules\SaasBilling\Contracts\RefundRefused;

/**
 * The console's side of refunds and invoice emails (P06-07b;
 * BILLING_PAYMENTS.md § 8; PLATFORM_CONSOLE.md § 1 two-person rules;
 * docs/plans/P06-subtasks.md defaults 55–59). Checks the staff member's
 * policy, then hands the money to SaasBilling (Contracts\PlatformRefunds),
 * which checks again:
 *
 * - at or below `billing.refund_approval_above_kobo` (counting the
 *   payment's earlier refunds) the refund runs at once;
 * - above it, the refund is kept waiting and an approval request
 *   (ApprovalAction::Refund) opens in the same transaction; a second staff
 *   member with `refunds.approve` approves it in Staff → Approvals
 *   (RefundApproval runs it), and a rejected or expired request refunds
 *   nothing.
 */
final readonly class Refunds
{
    public function __construct(private ApprovalService $approvals) {}

    public function refundable(PlatformPaymentRecord $payment): RefundablePayment
    {
        return $this->refunds()->refundable($payment->id);
    }

    public function approvalAbove(): Money
    {
        return Money::kobo($this->refunds()->approvalAboveKobo());
    }

    public function needsApproval(PlatformPaymentRecord $payment, int $amountKobo): bool
    {
        return $this->refunds()->needsApproval($payment->id, $amountKobo);
    }

    /**
     * Refunds now, or opens the approval request. Says which, in words.
     *
     * @throws AuthorizationException
     * @throws RefundRefused
     */
    public function request(PlatformStaff $staff, PlatformPaymentRecord $payment, int $amountKobo, string $reason, ?string $bankReference = null): string
    {
        if (! $staff->can('refund', $payment)) {
            throw new AuthorizationException;
        }

        $refunds = $this->refunds();
        $amount = Money::kobo(max(0, $amountKobo));

        if (! $refunds->needsApproval($payment->id, $amountKobo)) {
            $refunds->refundNow($staff, $payment->id, $amountKobo, $reason, $bankReference);

            return $payment->method === 'bank_transfer'
                ? "Recorded: {$amount->format()} refunded by bank transfer. The owners were told."
                : "Paystack is refunding {$amount->format()}. The owners were told.";
        }

        DB::transaction(function () use ($refunds, $staff, $payment, $amountKobo, $amount, $reason, $bankReference): void {
            $refundId = $refunds->requestApproval($staff, $payment->id, $amountKobo, $reason, $bankReference);
            $customer = $payment->customer()->first();
            $request = $this->approvals->request(
                ApprovalAction::Refund,
                $staff,
                null,
                sprintf('Refund %s of %s (%s, invoice %s)', $amount->format(), $payment->reference(), $customer->name ?? 'organization '.$payment->organization_id, (string) $payment->invoice()->value('number')),
                ['refund_id' => $refundId, 'payment_id' => $payment->id, 'organization_id' => $payment->organization_id, 'amount_kobo' => $amountKobo],
                $reason,
            );
            $refunds->attachApproval($refundId, $request->id);
        }, 3);

        return "Above {$this->approvalAbove()->format()}: the refund waits for a second staff member's approval in Staff → Approvals.";
    }

    /**
     * @throws AuthorizationException
     * @throws RefundRefused
     */
    public function check(PlatformStaff $staff, PlatformRefundRecord $refund): string
    {
        if (! $staff->can('check', $refund)) {
            throw new AuthorizationException;
        }

        $status = $this->refunds()->check($refund->id, $staff);

        return 'Paystack says: '.$status->label().'.';
    }

    /**
     * @throws AuthorizationException
     */
    public function resendInvoice(PlatformStaff $staff, PlatformInvoiceRecord $invoice): int
    {
        if (! $staff->can('resend', $invoice)) {
            throw new AuthorizationException;
        }

        if (! app()->bound(InvoiceEmails::class)) {
            throw new InvoiceEmailRefused('Invoices cannot be sent: the SaasBilling module is not enabled.');
        }

        return app(InvoiceEmails::class)->resend($staff, $invoice->id);
    }

    private function refunds(): PlatformRefunds
    {
        if (! app()->bound(PlatformRefunds::class)) {
            throw new RefundRefused('Refunds cannot be made: the SaasBilling module is not enabled.');
        }

        return app(PlatformRefunds::class);
    }
}
