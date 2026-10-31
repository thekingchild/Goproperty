<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Payments;

use App\Models\Organization;
use App\Sms\Credits\SmsCredits;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationOwners;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Enums\PaymentOutcome;
use Modules\SaasBilling\Enums\PlatformPaymentStatus;
use Modules\SaasBilling\Metering\SubscriptionMetering;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\PlatformPayment;
use Modules\SaasBilling\Notifications\PlatformPaymentReceived;
use Modules\SaasBilling\Notifications\SubscriptionPaymentFailed;
use Modules\SaasBilling\Paystack\PaystackUnavailable;
use Modules\SaasBilling\Paystack\PlatformPaystack;
use Modules\SaasBilling\Paystack\VerifiedCharge;
use Modules\SaasBilling\SmsCredits\SmsCreditPurchases;

/**
 * Settles a payment through GoProperty's own Paystack account
 * (BILLING_PAYMENTS.md § 4.2–4.3), whatever the invoice is for: an SMS
 * credit bundle (P05-03) or a subscription invoice (P06-03). Runs on the
 * return from checkout, on the webhook (`charge.success`, `charge.failed`),
 * on a replay of it and in the hourly reconciliation, always in the
 * payment's organization.
 *
 * It never trusts the caller: it asks Paystack (verify by fetch, § 4.2).
 * Only a successful charge for exactly what was asked, in naira, in the
 * account's mode, for an invoice still open, confirms the payment and marks
 * the invoice paid; anything else that took money is **held** for platform
 * staff and never applied (§ 4.3 rule 2). All in one transaction under the
 * payment's and the invoice's locks, so a payment is settled once however
 * often it is asked (rule 1 and 3). A failed charge fails the payment: an
 * SMS bundle's invoice is then void (the checkout did not happen); a
 * subscription invoice stays open, to be paid again, and its owners are
 * told (P06-05). An abandoned checkout
 * stays pending: the payer may still finish it.
 *
 * Confirmed: an SMS bundle adds its credits (App\Sms\Credits\SmsCredits);
 * a subscription invoice makes a lapsed subscription (`past_due`, `grace`,
 * `suspended`) active again once no other invoice is overdue (§ 6
 * "Reactivation": Payments\Reactivation, the same path as a confirmed
 * bank transfer), and its owners are told after the commit.
 */
final readonly class PlatformPaymentSettlement
{
    public function __construct(
        private PlatformPaystack $paystack,
        private SmsCredits $credits,
        private Reactivation $reactivation,
        private OrganizationContext $context,
        private OrganizationOwners $owners,
    ) {}

    /**
     * Settles the current organization's payment with this reference from
     * what Paystack says about it now.
     *
     * @throws PaystackUnavailable when Paystack cannot be asked now (nothing was recorded)
     */
    public function settle(string $reference): PaymentOutcome
    {
        $payment = PlatformPayment::query()->where('paystack_reference', $reference)->first();

        if (! $payment instanceof PlatformPayment) {
            return PaymentOutcome::Ignored;
        }

        if ($payment->status->isSettled()) {
            return PaymentOutcome::Duplicate;
        }

        if (! $this->paystack->available()) {
            throw new PaystackUnavailable('GoProperty\'s Paystack key is not set.');
        }

        // Verify by fetch, outside any lock (an HTTP call): only Paystack's answer counts.
        $verified = $this->paystack->verify($reference);

        if ($verified === null || $verified->reference !== $reference || ! $verified->succeeded()) {
            if ($verified !== null && $verified->reference === $reference && $verified->failed() && $payment->status === PlatformPaymentStatus::Pending) {
                $this->fail($payment->id, 'Paystack reports the payment '.$verified->status.'.', tellOwners: true);
            }

            return PaymentOutcome::NotPaid;
        }

        if (PlatformInvoice::query()->findOrFail($payment->platform_invoice_id)->kind === InvoiceKind::SmsCredits) {
            // Outside the transaction: the balance row is created before it is locked.
            $this->credits->ensureBalance();
        }

        return DB::transaction(fn (): PaymentOutcome => $this->confirm($payment->id, $verified), 3);
    }

    /**
     * A payment that did not happen (Paystack failed it, or the checkout
     * could not start): failed, and an SMS bundle's invoice void. A
     * subscription invoice stays open; when Paystack failed the charge
     * (`$tellOwners`), its owners are told once, with a Pay now link
     * (P06-05, BILLING_PAYMENTS.md § 6). Nothing is charged again by itself:
     * no card is kept.
     */
    public function fail(int $paymentId, string $reason, bool $tellOwners = false): void
    {
        DB::transaction(function () use ($paymentId, $reason, $tellOwners): void {
            $payment = PlatformPayment::query()->lockForUpdate()->findOrFail($paymentId);

            if ($payment->status !== PlatformPaymentStatus::Pending) {
                return;
            }

            $payment->status = PlatformPaymentStatus::Failed;
            $payment->hold_reason = mb_substr($reason, 0, 300);
            $payment->verified_at = CarbonImmutable::now();
            $payment->save();

            $invoice = PlatformInvoice::query()->lockForUpdate()->findOrFail($payment->platform_invoice_id);

            if ($invoice->kind === InvoiceKind::SmsCredits && $invoice->status === InvoiceStatus::Open) {
                $invoice->status = InvoiceStatus::Void;
                $invoice->voided_at = CarbonImmutable::now();
                $invoice->save();
            }

            if ($tellOwners && $invoice->kind === InvoiceKind::Subscription && $invoice->status === InvoiceStatus::Open) {
                foreach ($this->owners->owners($this->context->get()) as $owner) {
                    $owner->notify(new SubscriptionPaymentFailed($invoice->organization_id, $payment->id));
                }
            }
        }, 3);
    }

    private function confirm(int $paymentId, VerifiedCharge $verified): PaymentOutcome
    {
        $payment = PlatformPayment::query()->lockForUpdate()->findOrFail($paymentId);

        if ($payment->status->isSettled()) {
            return PaymentOutcome::Duplicate;
        }

        $invoice = PlatformInvoice::query()->lockForUpdate()->findOrFail($payment->platform_invoice_id);
        $paid = Money::kobo(max(0, $verified->amount));
        $now = CarbonImmutable::now();
        $reason = match (true) {
            $verified->currency !== 'NGN' => sprintf('Paid in %s, not naira.', $verified->currency),
            $verified->domain !== $this->paystack->mode() => sprintf('Paystack reports it in %s mode, not %s.', $verified->domain, $this->paystack->mode()),
            ! $paid->equals($payment->amount_kobo) => sprintf('%s was paid, but %s was asked for invoice %s.', $paid->format(), $payment->amount_kobo->format(), $invoice->number),
            $invoice->status !== InvoiceStatus::Open => sprintf('Invoice %s is %s.', $invoice->number, $invoice->status->value),
            default => null,
        };

        $payment->paystack_channel = $verified->channel;
        $payment->paid_amount_kobo = $paid;
        $payment->verified_at = $now;

        if ($reason !== null) {
            $payment->status = PlatformPaymentStatus::Held;
            $payment->hold_reason = mb_substr($reason.' Held for GoProperty staff to review; '.($invoice->kind === InvoiceKind::SmsCredits ? 'no credits were added.' : 'the invoice was not marked paid.'), 0, 300);
            $payment->save();
            $this->auditHeld($invoice, $payment, $paid, $verified, $reason);

            return PaymentOutcome::Held;
        }

        $payment->status = PlatformPaymentStatus::Confirmed;
        $payment->confirmed_at = $now;
        $payment->save();

        $invoice->status = InvoiceStatus::Paid;
        $invoice->paid_at = $now;
        $invoice->save();

        if ($invoice->kind === InvoiceKind::SmsCredits) {
            $entry = $this->credits->purchase((int) $invoice->sms_credits, $invoice->id, 'Invoice '.$invoice->number);

            activity(SmsCreditPurchases::AUDIT_LOG)
                ->event('sms_credits.purchased')
                ->performedOn($invoice)
                ->causedByAnonymous()
                ->withProperties(['invoice' => $invoice->number, 'reference' => $payment->paystack_reference, 'credits' => $invoice->sms_credits, 'total_kobo' => $invoice->total_kobo->kobo, 'balance_after' => $entry->balance_after])
                ->log('SMS credits bought');

            return PaymentOutcome::Confirmed;
        }

        $reactivated = $this->reactivation->afterPaid($invoice);

        activity(SubscriptionMetering::LOG)
            ->event('platform_invoice.paid')
            ->performedOn($invoice)
            ->causedByAnonymous()
            ->withProperties([
                'organization_id' => $invoice->organization_id,
                'invoice' => $invoice->number,
                'reference' => $payment->paystack_reference,
                'channel' => $verified->channel,
                'total_kobo' => $invoice->total_kobo->kobo,
                'subscription_reactivated' => $reactivated,
            ])
            ->log("Invoice {$invoice->number} paid through Paystack");

        foreach ($this->owners->owners($this->context->get()) as $owner) {
            $owner->notify(new PlatformPaymentReceived($invoice->organization_id, $payment->id));
        }

        return PaymentOutcome::Confirmed;
    }

    private function auditHeld(PlatformInvoice $invoice, PlatformPayment $payment, Money $paid, VerifiedCharge $verified, string $reason): void
    {
        $properties = ['invoice' => $invoice->number, 'reference' => $payment->paystack_reference, 'asked_kobo' => $payment->amount_kobo->kobo, 'paid_kobo' => $paid->kobo, 'currency' => $verified->currency, 'reason' => $reason];

        if ($invoice->kind === InvoiceKind::SmsCredits) {
            activity(SmsCreditPurchases::AUDIT_LOG)->event('sms_credits.payment_held')->performedOn($payment)->causedByAnonymous()
                ->withProperties($properties)->log('SMS credit payment held for review');

            return;
        }

        activity(SubscriptionMetering::LOG)->event('platform_payment.held')->performedOn($payment)->causedByAnonymous()
            ->withProperties($properties + ['organization_id' => $invoice->organization_id])->log('Subscription payment held for review');
    }

    /** The organization whose payment this is, by one unscoped read of its reference (for the webhook and reconciliation). */
    public static function organizationFor(string $reference): ?Organization
    {
        $organizationId = DB::table('platform_payments')->where('paystack_reference', $reference)->value('organization_id');

        return is_numeric($organizationId) ? Organization::query()->find((int) $organizationId) : null;
    }
}
