<?php

declare(strict_types=1);

namespace Modules\SaasBilling\SmsCredits;

use App\Marketing\PlanCatalogue;
use App\Models\User;
use App\Settings\SettingsStore;
use App\Sms\Credits\SmsCredits;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\SaasBilling\Contracts\CheckoutRefused;
use Modules\SaasBilling\Contracts\SmsCreditBundle;
use Modules\SaasBilling\Contracts\SmsCreditCheckout;
use Modules\SaasBilling\Contracts\SmsCreditPurchase;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Enums\PaymentOutcome;
use Modules\SaasBilling\Enums\PlatformPaymentStatus;
use Modules\SaasBilling\Invoices\PlatformInvoiceNumbers;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\PlatformPayment;
use Modules\SaasBilling\Paystack\PaystackUnavailable;
use Modules\SaasBilling\Paystack\PlatformPaystack;
use Modules\SaasBilling\Paystack\VerifiedCharge;

/**
 * SMS credit bundles bought in the app (P05-03; BILLING_PAYMENTS.md § 1.2,
 * § 4): the only writer of `platform_invoices` and `platform_payments` so
 * far. A bundle is a platform invoice (`PR-2026-000123`: credits × the
 * console's price per credit, plus 7.5 % VAT) paid through GoProperty's own
 * Paystack checkout (reference `PINV-{number}-a1`).
 *
 * settle() runs on the return from checkout and on Paystack's webhook. It
 * never trusts either: it asks Paystack (verify by fetch, § 4.2) and only a
 * successful charge for exactly the invoice's total, in naira, in the
 * account's mode, confirms the payment, marks the invoice paid and adds the
 * credits (App\Sms\Credits\SmsCredits::purchase), all in one transaction
 * under the payment's lock: once per payment however often it is called
 * (§ 4.3 rules 1 and 3). A charge that does not match is held for platform
 * staff (rule 2), never applied; a failed one voids the invoice (and one
 * paid after all is then held, never lost). An abandoned checkout stays
 * pending: the payer may still finish it.
 */
final readonly class SmsCreditPurchases implements SmsCreditCheckout
{
    public const string AUDIT_LOG = 'sms_credits';

    public function __construct(
        private OrganizationContext $context,
        private SettingsStore $settings,
        private PlanCatalogue $plans,
        private PlatformPaystack $paystack,
        private PlatformInvoiceNumbers $numbers,
        private SmsCredits $credits,
    ) {}

    public function available(): bool
    {
        return $this->paystack->available();
    }

    public function bundles(): array
    {
        $price = $this->settings->get('sms_credits.price_kobo');
        $price = is_int($price) && $price > 0 ? $price : 600;
        $sizes = $this->settings->get('sms_credits.bundles');
        $sizes = array_values(array_unique(array_filter(is_array($sizes) ? $sizes : [], static fn (mixed $size): bool => is_int($size) && $size > 0)));
        sort($sizes);

        return array_map(function (int $credits) use ($price): SmsCreditBundle {
            $subtotal = Money::kobo($price)->times($credits);
            $vat = $subtotal->percentage($this->vatBasisPoints());

            return new SmsCreditBundle($credits, $subtotal, $vat, $subtotal->plus($vat));
        }, $sizes);
    }

    public function start(User $buyer, int $credits, string $returnUrl): string
    {
        $bundle = null;

        foreach ($this->bundles() as $offered) {
            if ($offered->credits === $credits) {
                $bundle = $offered;
            }
        }

        if ($bundle === null) {
            throw new CheckoutRefused(__('That bundle is no longer offered. Pick one of the bundles shown.'));
        }

        if (! $this->paystack->available()) {
            throw new CheckoutRefused(__('Buying SMS credits online is not available yet. Contact support to add credits.'));
        }

        if ($buyer->email === null) {
            throw new CheckoutRefused(__('Paystack needs an email address for its receipt. Add one to your account (Settings → Profile), then buy again.'));
        }

        [$invoice, $payment] = DB::transaction(function () use ($buyer, $bundle): array {
            $invoice = new PlatformInvoice;
            $invoice->forceFill([
                'number' => $this->numbers->next(),
                'kind' => InvoiceKind::SmsCredits,
                'sms_credits' => $bundle->credits,
                'subtotal_kobo' => $bundle->price,
                'vat_kobo' => $bundle->vat,
                'total_kobo' => $bundle->total,
                'status' => InvoiceStatus::Open,
                'due_date' => CarbonImmutable::now('Africa/Lagos')->toDateString(),
                'created_by_user_id' => $buyer->id,
            ])->save();

            $payment = new PlatformPayment;
            $payment->forceFill([
                'platform_invoice_id' => $invoice->id,
                'method' => PlatformPayment::PAYSTACK,
                'amount_kobo' => $bundle->total,
                'currency' => 'NGN',
                'paystack_reference' => 'PINV-'.$invoice->number.'-a1',
                'status' => PlatformPaymentStatus::Pending,
            ])->save();

            return [$invoice, $payment];
        }, 3);

        try {
            return $this->paystack->initialize([
                'email' => $buyer->email,
                'amount' => (string) $bundle->total->kobo,
                'currency' => 'NGN',
                'reference' => $payment->paystack_reference,
                'callback_url' => $returnUrl,
                'metadata' => [
                    'invoice_number' => $invoice->number,
                    'organization' => $this->context->get()->slug,
                    'sms_credits' => $bundle->credits,
                ],
            ]);
        } catch (PaystackUnavailable $problem) {
            $this->close($payment->id, PlatformPaymentStatus::Failed, 'Paystack did not start the checkout: '.$problem->getMessage());

            throw new CheckoutRefused(__('Paystack could not start the payment just now. Try again in a few minutes.'));
        }
    }

    public function purchases(int $limit = 10): array
    {
        $invoices = PlatformInvoice::query()
            ->where('kind', InvoiceKind::SmsCredits)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
        $payments = PlatformPayment::query()
            ->whereIn('platform_invoice_id', $invoices->modelKeys())
            ->orderBy('id')
            ->get()
            ->keyBy('platform_invoice_id');

        return array_values($invoices->map(static function (PlatformInvoice $invoice) use ($payments): SmsCreditPurchase {
            $payment = $payments->get($invoice->id);
            $status = $payment instanceof PlatformPayment ? $payment->status : PlatformPaymentStatus::Pending;

            return new SmsCreditPurchase(
                invoiceNumber: $invoice->number,
                credits: (int) $invoice->sms_credits,
                total: $invoice->total_kobo,
                status: $status === PlatformPaymentStatus::Confirmed ? 'paid' : $status->value,
                statusLabel: $status->label(),
                startedAt: $invoice->created_at ?? CarbonImmutable::now(),
                paidAt: $invoice->paid_at,
            );
        })->all());
    }

    /**
     * Settles the current organization's payment with this reference from
     * what Paystack says about it now (see the class comment).
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
                $this->close($payment->id, PlatformPaymentStatus::Failed, 'Paystack reports the payment '.$verified->status.'.');
            }

            return PaymentOutcome::NotPaid;
        }

        // Outside the transaction: the balance row is created before it is locked.
        $this->credits->ensureBalance();

        return DB::transaction(fn (): PaymentOutcome => $this->confirm($payment->id, $verified), 3);
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
            $payment->hold_reason = mb_substr($reason.' Held for GoProperty staff to review; no credits were added.', 0, 300);
            $payment->save();

            activity(self::AUDIT_LOG)
                ->event('sms_credits.payment_held')
                ->performedOn($payment)
                ->causedByAnonymous()
                ->withProperties(['invoice' => $invoice->number, 'reference' => $payment->paystack_reference, 'asked_kobo' => $payment->amount_kobo->kobo, 'paid_kobo' => $paid->kobo, 'currency' => $verified->currency, 'reason' => $reason])
                ->log('SMS credit payment held for review');

            return PaymentOutcome::Held;
        }

        $payment->status = PlatformPaymentStatus::Confirmed;
        $payment->confirmed_at = $now;
        $payment->save();

        $invoice->status = InvoiceStatus::Paid;
        $invoice->paid_at = $now;
        $invoice->save();

        $entry = $this->credits->purchase((int) $invoice->sms_credits, $invoice->id, 'Invoice '.$invoice->number);

        activity(self::AUDIT_LOG)
            ->event('sms_credits.purchased')
            ->performedOn($invoice)
            ->causedByAnonymous()
            ->withProperties(['invoice' => $invoice->number, 'reference' => $payment->paystack_reference, 'credits' => $invoice->sms_credits, 'total_kobo' => $invoice->total_kobo->kobo, 'balance_after' => $entry->balance_after])
            ->log('SMS credits bought');

        return PaymentOutcome::Confirmed;
    }

    /** A checkout that did not happen: the payment failed, its invoice void. */
    private function close(int $paymentId, PlatformPaymentStatus $status, string $reason): void
    {
        DB::transaction(function () use ($paymentId, $status, $reason): void {
            $payment = PlatformPayment::query()->lockForUpdate()->findOrFail($paymentId);

            if ($payment->status !== PlatformPaymentStatus::Pending) {
                return;
            }

            $payment->status = $status;
            $payment->hold_reason = mb_substr($reason, 0, 300);
            $payment->verified_at = CarbonImmutable::now();
            $payment->save();

            $invoice = PlatformInvoice::query()->lockForUpdate()->findOrFail($payment->platform_invoice_id);

            if ($invoice->status === InvoiceStatus::Open) {
                $invoice->status = InvoiceStatus::Void;
                $invoice->voided_at = CarbonImmutable::now();
                $invoice->save();
            }
        }, 3);
    }

    /** "7.5" → 750 basis points (BILLING_PAYMENTS.md § 1.4; PlanCatalogue::vatPercent()). */
    private function vatBasisPoints(): int
    {
        $percent = $this->plans->vatPercent();

        if (preg_match('/^(\d{1,2})(?:\.(\d{1,2}))?$/', $percent, $parts) !== 1) {
            return 750;
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
    }
}
