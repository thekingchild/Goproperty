<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Payments;

use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationOwners;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\SaasBilling\Contracts\BankTransferReviews;
use Modules\SaasBilling\Contracts\TransferReviewRefused;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Enums\PlatformPaymentStatus;
use Modules\SaasBilling\Metering\SubscriptionMetering;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\PlatformPayment;
use Modules\SaasBilling\Notifications\BankTransferRejected;
use Modules\SaasBilling\Notifications\PlatformPaymentReceived;

/**
 * Platform staff confirm or reject a bank-transfer proof (P06-04;
 * BILLING_PAYMENTS.md § 5 steps 3–4; docs/plans/P06-subtasks.md defaults
 * 35–37), the only way a proof changes. Needs `payments.confirm_transfer`
 * (finance-admin, super-admin), checked here again whatever the caller did.
 *
 * Confirm: the invoice's whole total reached GoProperty's account. In one
 * transaction, inside the payment's organization, under the payment's and
 * the invoice's locks: the payment `confirmed` (who and when), the invoice
 * `paid`, a lapsed subscription active again by the same rule as a
 * Paystack payment (Payments\Reactivation, § 6), and one audit entry
 * `platform_invoice.paid` with the staff member as causer. The owners get
 * the same receipt as for Paystack after the commit. Refused when the
 * invoice is no longer open (paid by card meanwhile: reject the proof).
 * There are no part payments: a transfer short of the total is rejected
 * with the reason.
 *
 * Reject: with a reason (3–500 characters), the payment `rejected` and
 * kept, the invoice still open, audited `platform_payment.transfer_rejected`,
 * the owners told with the reason (they may send another proof).
 */
final readonly class TransferReviews implements BankTransferReviews
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationOwners $owners,
        private Reactivation $reactivation,
    ) {}

    public function confirm(PlatformStaff $staff, int $paymentId): void
    {
        $this->authorize($staff);
        $organization = $this->organizationOf($paymentId);

        $this->context->run($organization, function () use ($staff, $paymentId, $organization): void {
            DB::transaction(function () use ($staff, $paymentId): void {
                [$payment, $invoice] = $this->lockedPending($paymentId);

                if ($invoice->kind !== InvoiceKind::Subscription || $invoice->status !== InvoiceStatus::Open) {
                    throw new TransferReviewRefused(sprintf('Invoice %s is %s, so this transfer cannot pay it. Reject the proof with the reason instead.', $invoice->number, $invoice->status->value));
                }

                $now = CarbonImmutable::now();
                $payment->status = PlatformPaymentStatus::Confirmed;
                $payment->paid_amount_kobo = $payment->amount_kobo;
                $payment->confirmed_at = $now;
                $payment->verified_at = $now;
                $payment->reviewed_by_staff_id = $staff->id;
                $payment->reviewed_at = $now;
                $payment->save();

                $invoice->status = InvoiceStatus::Paid;
                $invoice->paid_at = $now;
                $invoice->save();

                $reactivated = $this->reactivation->afterPaid($invoice);

                activity(SubscriptionMetering::LOG)
                    ->event('platform_invoice.paid')
                    ->performedOn($invoice)
                    ->causedBy($staff)
                    ->withProperties([
                        'organization_id' => $invoice->organization_id,
                        'invoice' => $invoice->number,
                        'method' => PlatformPayment::BANK_TRANSFER,
                        'payment_id' => $payment->id,
                        'total_kobo' => $invoice->total_kobo->kobo,
                        'stated_amount_kobo' => $payment->stated_amount_kobo?->kobo,
                        'transferred_on' => $payment->transferred_on?->toDateString(),
                        'confirmed_by_staff_id' => $staff->id,
                        'subscription_reactivated' => $reactivated,
                    ])
                    ->log("Invoice {$invoice->number} paid by bank transfer, confirmed by {$staff->name}");
            }, 3);

            foreach ($this->owners->owners($organization) as $owner) {
                $owner->notify(new PlatformPaymentReceived($organization->id, $paymentId));
            }
        });
    }

    public function reject(PlatformStaff $staff, int $paymentId, string $reason): void
    {
        $this->authorize($staff);
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        $check = Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:500']]);

        if ($check->fails()) {
            throw new TransferReviewRefused('Say why the proof is not accepted (3 to 500 characters): the customer is shown it.');
        }

        $organization = $this->organizationOf($paymentId);

        $this->context->run($organization, function () use ($staff, $paymentId, $reason, $organization): void {
            DB::transaction(function () use ($staff, $paymentId, $reason): void {
                [$payment, $invoice] = $this->lockedPending($paymentId);

                $now = CarbonImmutable::now();
                $payment->status = PlatformPaymentStatus::Rejected;
                $payment->rejection_reason = $reason;
                $payment->reviewed_by_staff_id = $staff->id;
                $payment->reviewed_at = $now;
                $payment->save();

                activity(SubscriptionMetering::LOG)
                    ->event('platform_payment.transfer_rejected')
                    ->performedOn($payment)
                    ->causedBy($staff)
                    ->withProperties([
                        'organization_id' => $invoice->organization_id,
                        'invoice' => $invoice->number,
                        'stated_amount_kobo' => $payment->stated_amount_kobo?->kobo,
                        'reason' => $reason,
                    ])
                    ->log("Bank transfer proof for invoice {$invoice->number} not accepted");
            }, 3);

            foreach ($this->owners->owners($organization) as $owner) {
                $owner->notify(new BankTransferRejected($organization->id, $paymentId));
            }
        });
    }

    private function authorize(PlatformStaff $staff): void
    {
        if (! $staff->holdsStaffPermission(StaffPermission::PaymentsConfirmTransfer)) {
            throw new TransferReviewRefused('Confirming bank transfers needs the "Confirm bank-transfer subscription payments" permission.');
        }
    }

    /** The organization of a bank-transfer payment, by one unscoped read of the row. */
    private function organizationOf(int $paymentId): Organization
    {
        $organizationId = DB::table('platform_payments')
            ->where('id', $paymentId)
            ->where('method', PlatformPayment::BANK_TRANSFER)
            ->value('organization_id');
        $organization = is_numeric($organizationId) ? Organization::query()->find((int) $organizationId) : null;

        if (! $organization instanceof Organization) {
            throw new TransferReviewRefused('That bank transfer was not found.');
        }

        return $organization;
    }

    /**
     * The payment, still waiting, and its invoice, both locked.
     *
     * @return array{PlatformPayment, PlatformInvoice}
     */
    private function lockedPending(int $paymentId): array
    {
        $payment = PlatformPayment::query()->lockForUpdate()->findOrFail($paymentId);

        if (! $payment->isBankTransfer() || $payment->status !== PlatformPaymentStatus::Pending) {
            throw new TransferReviewRefused(sprintf('This proof was %s already.', $payment->status === PlatformPaymentStatus::Rejected ? 'rejected' : 'reviewed'));
        }

        return [$payment, PlatformInvoice::query()->lockForUpdate()->findOrFail($payment->platform_invoice_id)];
    }
}
