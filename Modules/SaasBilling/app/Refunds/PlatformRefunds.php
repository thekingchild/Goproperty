<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Refunds;

use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission;
use App\Settings\SettingsStore;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationOwners;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\SaasBilling\Contracts\PlatformRefunds as PlatformRefundsContract;
use Modules\SaasBilling\Contracts\RefundablePayment;
use Modules\SaasBilling\Contracts\RefundRefused;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Enums\PlatformPaymentStatus;
use Modules\SaasBilling\Enums\RefundStatus;
use Modules\SaasBilling\Metering\SubscriptionMetering;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\PlatformPayment;
use Modules\SaasBilling\Models\PlatformRefund;
use Modules\SaasBilling\Notifications\PlatformRefundIssued;
use Modules\SaasBilling\Paystack\PaystackUnavailable;
use Modules\SaasBilling\Paystack\PlatformPaystack;

/**
 * The only writer of `platform_refunds` (P06-07b; BILLING_PAYMENTS.md § 8;
 * docs/plans/P06-subtasks.md defaults 57–59). See Contracts\PlatformRefunds.
 *
 * - Refundable: a confirmed or held payment, up to what it paid less what
 *   is refunded, being refunded or waiting for approval.
 * - Approval: above `billing.refund_approval_above_kobo`, counting the
 *   payment's earlier refunds (so splitting a refund never avoids it).
 * - Running one, in the payment's organization, in one transaction under
 *   the payment's, the invoice's and the refund's locks: a Paystack payment
 *   is refunded through Paystack's API (it answers `processing`, later
 *   `processed` or `failed`: check()); a bank transfer was refunded by
 *   GoProperty's own transfer and is recorded as refunded with its
 *   reference. When the whole payment that paid a subscription invoice is
 *   refunded, the invoice is open again, due 7 days later (default 58).
 *   One audit entry `platform_refund.issued` (log `platform_invoices`);
 *   the owners are emailed after the commit.
 * - Paystack is asked before anything is written: when it refuses or
 *   cannot be reached, nothing is recorded and the staff member is told.
 */
final readonly class PlatformRefunds implements PlatformRefundsContract
{
    public const string APPROVAL_SETTING = 'billing.refund_approval_above_kobo';

    /** A reopened invoice is due this many days after the refund (net 7, as when it was issued). */
    public const int REOPENED_DUE_DAYS = 7;

    public function __construct(
        private OrganizationContext $context,
        private OrganizationOwners $owners,
        private PlatformPaystack $paystack,
        private SettingsStore $settings,
    ) {}

    public function refundable(int $paymentId): RefundablePayment
    {
        return $this->context->run($this->organizationOf($paymentId), function () use ($paymentId): RefundablePayment {
            $payment = PlatformPayment::query()->with('invoice')->findOrFail($paymentId);

            return $this->summary($payment);
        });
    }

    public function needsApproval(int $paymentId, int $amountKobo): bool
    {
        $summary = $this->refundable($paymentId);

        return $summary->refunded->plus($summary->awaitingApproval)->plus(Money::kobo(max(0, $amountKobo)))->kobo > $this->approvalAboveKobo();
    }

    public function approvalAboveKobo(): int
    {
        $value = $this->settings->get(self::APPROVAL_SETTING);

        return is_int($value) && $value >= 0 ? $value : 10_000_000;
    }

    public function refundNow(PlatformStaff $staff, int $paymentId, int $amountKobo, string $reason, ?string $bankReference = null): int
    {
        $this->authorize($staff, StaffPermission::RefundsCreate);
        [$reason, $bankReference] = $this->checked($reason, $bankReference);
        $organization = $this->organizationOf($paymentId);

        if ($this->needsApproval($paymentId, $amountKobo)) {
            throw new RefundRefused('This refund is above '.Money::kobo($this->approvalAboveKobo())->format().' for the payment, so a second staff member must approve it.');
        }

        $refundId = $this->context->run($organization, fn (): int => DB::transaction(function () use ($staff, $paymentId, $amountKobo, $reason, $bankReference): int {
            [$payment, $invoice] = $this->lockedPayment($paymentId, $amountKobo, $bankReference);
            $refund = $this->newRefund($staff, $payment, $amountKobo, $reason, $bankReference);
            $this->run($refund, $payment, $invoice, $staff);

            return $refund->id;
        }, 3));

        return $refundId;
    }

    public function requestApproval(PlatformStaff $staff, int $paymentId, int $amountKobo, string $reason, ?string $bankReference = null): int
    {
        $this->authorize($staff, StaffPermission::RefundsCreate);
        [$reason, $bankReference] = $this->checked($reason, $bankReference);

        return $this->context->run($this->organizationOf($paymentId), fn (): int => DB::transaction(function () use ($staff, $paymentId, $amountKobo, $reason, $bankReference): int {
            [$payment] = $this->lockedPayment($paymentId, $amountKobo, $bankReference);
            $refund = $this->newRefund($staff, $payment, $amountKobo, $reason, $bankReference);

            activity(SubscriptionMetering::LOG)
                ->event('platform_refund.requested')
                ->performedOn($refund)
                ->causedBy($staff)
                ->withProperties($this->properties($refund, $payment))
                ->log(sprintf('Refund of %s requested for payment %s, waiting for approval', $refund->amount_kobo->format(), $this->paymentLabel($payment)));

            return $refund->id;
        }, 3));
    }

    public function attachApproval(int $refundId, int $approvalRequestId): void
    {
        $this->context->run($this->organizationOfRefund($refundId), function () use ($refundId, $approvalRequestId): void {
            DB::transaction(function () use ($refundId, $approvalRequestId): void {
                $refund = PlatformRefund::query()->lockForUpdate()->findOrFail($refundId);

                if ($refund->status !== RefundStatus::Requested || $refund->approval_request_id !== null) {
                    throw new RefundRefused('This refund is not waiting for an approval request.');
                }

                $refund->approval_request_id = $approvalRequestId;
                $refund->save();
            }, 3);
        });
    }

    public function approve(PlatformStaff $approver, int $refundId, int $approvalRequestId): void
    {
        $this->authorize($approver, StaffPermission::RefundsApprove);

        $this->context->run($this->organizationOfRefund($refundId), function () use ($approver, $refundId, $approvalRequestId): void {
            DB::transaction(function () use ($approver, $refundId, $approvalRequestId): void {
                $refund = PlatformRefund::query()->findOrFail($refundId);

                if ($refund->approval_request_id !== $approvalRequestId || $refund->status !== RefundStatus::Requested) {
                    throw new RefundRefused('This refund is no longer waiting for this approval.');
                }

                if ($refund->requested_by_staff_id === $approver->id) {
                    throw new RefundRefused('You cannot approve your own refund: another staff member must.');
                }

                [$payment, $invoice] = $this->lockedPayment($refund->platform_payment_id, $refund->amount_kobo->kobo, $refund->bank_reference, $refund->id);
                $refund = PlatformRefund::query()->lockForUpdate()->findOrFail($refundId);
                $this->run($refund, $payment, $invoice, $approver);
            }, 3);
        });
    }

    public function notApproved(int $refundId): void
    {
        $this->context->run($this->organizationOfRefund($refundId), function () use ($refundId): void {
            DB::transaction(function () use ($refundId): void {
                $refund = PlatformRefund::query()->lockForUpdate()->findOrFail($refundId);

                if ($refund->status !== RefundStatus::Requested) {
                    return;
                }

                $refund->status = RefundStatus::NotApproved;
                $refund->save();

                activity(SubscriptionMetering::LOG)
                    ->event('platform_refund.not_approved')
                    ->performedOn($refund)
                    ->withProperties(['organization_id' => $refund->organization_id, 'refund_id' => $refund->id, 'approval_request_id' => $refund->approval_request_id, 'amount_kobo' => $refund->amount_kobo->kobo])
                    ->log(sprintf('Refund of %s not approved: nothing was refunded', $refund->amount_kobo->format()));
            }, 3);
        });
    }

    public function check(int $refundId, ?PlatformStaff $staff = null): RefundStatus
    {
        if ($staff !== null && ! $staff->holdsStaffPermission(StaffPermission::RefundsCreate) && ! $staff->holdsStaffPermission(StaffPermission::InvoicesManage)) {
            throw new RefundRefused('Checking a refund needs the "Request refunds" or "Platform invoices" permission.');
        }

        return $this->context->run($this->organizationOfRefund($refundId), function () use ($refundId, $staff): RefundStatus {
            $refund = PlatformRefund::query()->findOrFail($refundId);

            if ($refund->status !== RefundStatus::Processing || $refund->paystack_refund_id === null) {
                return $refund->status;
            }

            try {
                $answer = $this->paystack->fetchRefund($refund->paystack_refund_id);
            } catch (PaystackUnavailable $unavailable) {
                throw new RefundRefused($unavailable->getMessage(), previous: $unavailable);
            }

            return DB::transaction(function () use ($refundId, $answer, $staff): RefundStatus {
                $refund = PlatformRefund::query()->lockForUpdate()->findOrFail($refundId);

                if ($refund->status !== RefundStatus::Processing) {
                    return $refund->status;
                }

                $refund->checked_at = CarbonImmutable::now();

                if ($answer === null || $answer->failed()) {
                    $refund->status = RefundStatus::Failed;
                    $refund->failure_reason = $answer === null ? 'Paystack has no refund with this id.' : 'Paystack reports the refund as '.$answer->status.'.';
                } elseif ($answer->processed()) {
                    $refund->status = RefundStatus::Processed;
                    $refund->processed_at = CarbonImmutable::now();
                }

                $refund->save();

                if ($refund->status !== RefundStatus::Processing) {
                    $entry = activity(SubscriptionMetering::LOG)
                        ->event('platform_refund.'.$refund->status->value)
                        ->performedOn($refund)
                        ->withProperties(['organization_id' => $refund->organization_id, 'refund_id' => $refund->id, 'paystack_refund_id' => $refund->paystack_refund_id, 'failure_reason' => $refund->failure_reason]);

                    if ($staff !== null) {
                        $entry->causedBy($staff);
                    }

                    $entry->log(sprintf('Refund of %s %s', $refund->amount_kobo->format(), $refund->status === RefundStatus::Processed ? 'processed by Paystack' : 'failed at Paystack'));
                }

                return $refund->status;
            }, 3);
        });
    }

    public function checkPayment(string $paystackReference): int
    {
        $row = DB::table('platform_payments')->where('paystack_reference', $paystackReference)->first(['id', 'organization_id']);

        if ($row === null) {
            return 0;
        }

        $organization = Organization::query()->find((int) $row->organization_id);

        if (! $organization instanceof Organization) {
            return 0;
        }

        $ids = $this->context->run($organization, static fn (): array => PlatformRefund::query()
            ->where('platform_payment_id', (int) $row->id)
            ->where('status', RefundStatus::Processing)
            ->whereNotNull('paystack_refund_id')
            ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());

        $changed = 0;

        foreach ($ids as $id) {
            try {
                $changed += $this->check($id) !== RefundStatus::Processing ? 1 : 0;
            } catch (RefundRefused $refused) {
                throw new PaystackUnavailable($refused->getMessage(), previous: $refused);
            }
        }

        return $changed;
    }

    /** Asks Paystack (or records the bank transfer) and applies what a refund changes. */
    private function run(PlatformRefund $refund, PlatformPayment $payment, PlatformInvoice $invoice, PlatformStaff $by): void
    {
        $now = CarbonImmutable::now();

        if ($payment->isBankTransfer()) {
            $refund->status = RefundStatus::Processed;
            $refund->processed_at = $now;
        } else {
            if (! $this->paystack->available() || $payment->paystack_reference === null) {
                throw new RefundRefused('GoProperty\'s Paystack account is not set up here, so nothing can be refunded through it.');
            }

            try {
                $answer = $this->paystack->refund($payment->paystack_reference, $refund->amount_kobo->kobo, "GoProperty refund {$refund->id}: {$refund->reason}");
            } catch (PaystackUnavailable $unavailable) {
                throw new RefundRefused($unavailable->getMessage().' Nothing was refunded.', previous: $unavailable);
            }

            if ($answer->failed()) {
                throw new RefundRefused('Paystack refused the refund ('.$answer->status.'). Nothing was refunded.');
            }

            $refund->paystack_refund_id = $answer->id;
            $refund->status = $answer->processed() ? RefundStatus::Processed : RefundStatus::Processing;
            $refund->processed_at = $answer->processed() ? $now : null;
        }

        $refund->executed_by_staff_id = $by->id;
        $refund->executed_at = $now;

        $alreadyRefunded = Money::sum(PlatformRefund::query()
            ->where('platform_payment_id', $payment->id)
            ->whereKeyNot($refund->id)
            ->whereIn('status', [RefundStatus::Processing->value, RefundStatus::Processed->value])
            ->pluck('amount_kobo'));
        $whole = $alreadyRefunded->plus($refund->amount_kobo)->greaterThanOrEqual($this->paid($payment));

        if ($whole && $payment->status === PlatformPaymentStatus::Confirmed && $invoice->kind === InvoiceKind::Subscription && $invoice->status === InvoiceStatus::Paid) {
            $invoice->reopenAfterRefund($now->setTimezone('Africa/Lagos')->startOfDay()->addDays(self::REOPENED_DUE_DAYS));
            $refund->invoice_reopened = true;
        }

        $refund->save();

        activity(SubscriptionMetering::LOG)
            ->event('platform_refund.issued')
            ->performedOn($refund)
            ->causedBy($by)
            ->withProperties($this->properties($refund, $payment) + [
                'executed_by_staff_id' => $by->id,
                'whole_payment' => $whole,
                'invoice_reopened' => $refund->invoice_reopened,
                'invoice_due_date' => $refund->invoice_reopened ? $invoice->due_date?->toDateString() : null,
            ])
            ->log(sprintf('Refunded %s of payment %s%s', $refund->amount_kobo->format(), $this->paymentLabel($payment), $refund->invoice_reopened ? "; invoice {$invoice->number} is open again" : ''));

        foreach ($this->owners->owners($this->context->get()) as $owner) {
            $owner->notify(new PlatformRefundIssued($refund->organization_id, $refund->id));
        }
    }

    /**
     * The payment and its invoice, locked, after checking it can be refunded
     * by $amountKobo (leaving out $exceptRefundId's own hold).
     *
     * @return array{PlatformPayment, PlatformInvoice}
     */
    private function lockedPayment(int $paymentId, int $amountKobo, ?string $bankReference, ?int $exceptRefundId = null): array
    {
        $payment = PlatformPayment::query()->lockForUpdate()->findOrFail($paymentId);
        $invoice = PlatformInvoice::query()->lockForUpdate()->findOrFail($payment->platform_invoice_id);
        $payment->setRelation('invoice', $invoice);

        if (! self::refundableStatus($payment)) {
            throw new RefundRefused(sprintf('This payment is %s, so there is nothing to refund.', $payment->status->value));
        }

        if ($payment->isBankTransfer() && ($bankReference === null || $bankReference === '')) {
            throw new RefundRefused('Give the reference of GoProperty\'s transfer back to the customer.');
        }

        $summary = $this->summary($payment, $exceptRefundId);

        if ($summary->remaining()->isZero()) {
            throw new RefundRefused(sprintf('Nothing is left to refund: the %s this payment took is refunded or waiting for approval already.', $summary->paid->format()));
        }

        if ($amountKobo < 1 || $amountKobo > $summary->remaining()->kobo) {
            throw new RefundRefused(sprintf('A refund of this payment is between ₦0.01 and %s (paid %s; refunded or waiting %s).', $summary->remaining()->format(), $summary->paid->format(), $summary->refunded->plus($summary->awaitingApproval)->format()));
        }

        return [$payment, $invoice];
    }

    private function newRefund(PlatformStaff $staff, PlatformPayment $payment, int $amountKobo, string $reason, ?string $bankReference): PlatformRefund
    {
        $refund = new PlatformRefund;
        $refund->forceFill([
            'organization_id' => $payment->organization_id,
            'platform_payment_id' => $payment->id,
            'platform_invoice_id' => $payment->platform_invoice_id,
            'method' => $payment->method,
            'amount_kobo' => Money::kobo($amountKobo),
            'status' => RefundStatus::Requested,
            'reason' => $reason,
            'requested_by_staff_id' => $staff->id,
            'bank_reference' => $payment->isBankTransfer() ? $bankReference : null,
        ])->save();

        return $refund;
    }

    private function summary(PlatformPayment $payment, ?int $exceptRefundId = null): RefundablePayment
    {
        $refunds = PlatformRefund::query()
            ->where('platform_payment_id', $payment->id)
            ->whereIn('status', RefundStatus::holdingValues())
            ->when($exceptRefundId !== null, static fn ($query) => $query->whereKeyNot($exceptRefundId))
            ->get(['amount_kobo', 'status']);

        return new RefundablePayment(
            paymentId: $payment->id,
            organizationId: $payment->organization_id,
            invoiceNumber: $payment->invoice->number,
            method: $payment->method,
            refundableStatus: self::refundableStatus($payment),
            paid: $this->paid($payment),
            refunded: Money::sum($refunds->filter(static fn (PlatformRefund $refund): bool => $refund->status->refunds())->pluck('amount_kobo')),
            awaitingApproval: Money::sum($refunds->filter(static fn (PlatformRefund $refund): bool => $refund->status === RefundStatus::Requested)->pluck('amount_kobo')),
        );
    }

    private static function refundableStatus(PlatformPayment $payment): bool
    {
        return in_array($payment->status, [PlatformPaymentStatus::Confirmed, PlatformPaymentStatus::Held], true);
    }

    /** What the payment took: Paystack's verified amount (a held one may differ from what was asked). */
    private function paid(PlatformPayment $payment): Money
    {
        return $payment->paid_amount_kobo ?? $payment->amount_kobo;
    }

    /** @return array<string, mixed> */
    private function properties(PlatformRefund $refund, PlatformPayment $payment): array
    {
        return [
            'organization_id' => $refund->organization_id,
            'refund_id' => $refund->id,
            'payment_id' => $payment->id,
            'invoice' => $payment->invoice->number,
            'method' => $refund->method,
            'amount_kobo' => $refund->amount_kobo->kobo,
            'paid_kobo' => $this->paid($payment)->kobo,
            'payment_status' => $payment->status->value,
            'reason' => $refund->reason,
            'requested_by_staff_id' => $refund->requested_by_staff_id,
            'approval_request_id' => $refund->approval_request_id,
            'paystack_refund_id' => $refund->paystack_refund_id,
            'bank_reference' => $refund->bank_reference,
        ];
    }

    private function paymentLabel(PlatformPayment $payment): string
    {
        return $payment->paystack_reference ?? 'bank transfer for '.$payment->invoice->number;
    }

    /** @return array{string, string|null} */
    private function checked(string $reason, ?string $bankReference): array
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        $bankReference = $bankReference !== null ? trim((string) preg_replace('/\s+/u', ' ', $bankReference)) : null;

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new RefundRefused('Say why the money is given back (3 to 500 characters): it is kept in the audit log.');
        }

        if ($bankReference !== null && $bankReference !== '' && (mb_strlen($bankReference) < 3 || mb_strlen($bankReference) > 100 || preg_match('/[<>]/', $bankReference) === 1)) {
            throw new RefundRefused('The transfer reference is 3 to 100 characters of plain text.');
        }

        return [$reason, $bankReference === '' ? null : $bankReference];
    }

    private function authorize(PlatformStaff $staff, StaffPermission $permission): void
    {
        if (! $staff->holdsStaffPermission($permission)) {
            throw new RefundRefused(sprintf('This needs the "%s" permission.', $permission->label()));
        }
    }

    private function organizationOf(int $paymentId): Organization
    {
        $organizationId = DB::table('platform_payments')->where('id', $paymentId)->value('organization_id');
        $organization = is_numeric($organizationId) ? Organization::query()->find((int) $organizationId) : null;

        return $organization instanceof Organization ? $organization : throw new RefundRefused('That payment was not found.');
    }

    private function organizationOfRefund(int $refundId): Organization
    {
        $organizationId = DB::table('platform_refunds')->where('id', $refundId)->value('organization_id');
        $organization = is_numeric($organizationId) ? Organization::query()->find((int) $organizationId) : null;

        return $organization instanceof Organization ? $organization : throw new RefundRefused('That refund was not found.');
    }
}
