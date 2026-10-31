<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Payments;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Modules\SaasBilling\Contracts\CheckoutRefused;
use Modules\SaasBilling\Contracts\SubscriptionPayments;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Enums\PlatformPaymentStatus;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\PlatformPayment;
use Modules\SaasBilling\Paystack\PaystackUnavailable;
use Modules\SaasBilling\Paystack\PlatformPaystack;

/**
 * Pays a subscription invoice through GoProperty's own Paystack checkout
 * (P06-03; BILLING_PAYMENTS.md § 4.1 "a real invoice charge"). Each attempt
 * is its own `platform_payments` row with reference
 * `PINV-{number}-a{n}` (n = 1, 2, …), for exactly the invoice's total.
 *
 * Before a new attempt, the invoice's attempts still pending are asked
 * about (a payer who paid and never came back is not asked to pay twice).
 * The attempt is written under the invoice's lock, refused once the invoice
 * is no longer open. No card is kept for later charges (the product
 * owner's decision of 2026-10-30): each invoice is paid at checkout.
 */
final readonly class SubscriptionCheckout implements SubscriptionPayments
{
    public function __construct(
        private OrganizationContext $context,
        private PlatformPaystack $paystack,
        private PlatformPaymentSettlement $settlement,
    ) {}

    public function available(): bool
    {
        return $this->paystack->available();
    }

    public function start(User $payer, string $invoiceNumber, string $returnUrl): string
    {
        if (! $this->paystack->available()) {
            throw new CheckoutRefused(__('Paying online is not available yet. Pay by bank transfer instead.'));
        }

        if ($payer->email === null) {
            throw new CheckoutRefused(__('Paystack needs an email address for its receipt. Add one to your account (Settings → Profile), then pay again.'));
        }

        $invoice = $this->openInvoice($invoiceNumber);
        $this->askAboutPendingAttempts($invoice);

        $payment = DB::transaction(function () use ($invoice, $payer): PlatformPayment {
            $locked = PlatformInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status !== InvoiceStatus::Open) {
                throw new CheckoutRefused(__('Invoice :number is already paid.', ['number' => $locked->number]));
            }

            $attempt = PlatformPayment::query()->where('platform_invoice_id', $locked->id)->where('method', PlatformPayment::PAYSTACK)->count() + 1;
            $payment = new PlatformPayment;
            $payment->forceFill([
                'platform_invoice_id' => $locked->id,
                'method' => PlatformPayment::PAYSTACK,
                'amount_kobo' => $locked->total_kobo,
                'currency' => 'NGN',
                'paystack_reference' => 'PINV-'.$locked->number.'-a'.$attempt,
                'status' => PlatformPaymentStatus::Pending,
                'initiated_by_user_id' => $payer->id,
            ])->save();

            return $payment;
        }, 3);

        try {
            return $this->paystack->initialize([
                'email' => $payer->email,
                'amount' => (string) $payment->amount_kobo->kobo,
                'currency' => 'NGN',
                'reference' => $payment->paystack_reference,
                'callback_url' => $returnUrl,
                'channels' => ['card', 'bank', 'ussd', 'bank_transfer'],
                'metadata' => [
                    'invoice_number' => $invoice->number,
                    'organization' => $this->context->get()->slug,
                    'kind' => InvoiceKind::Subscription->value,
                ],
            ]);
        } catch (PaystackUnavailable $problem) {
            $this->settlement->fail($payment->id, 'Paystack did not start the checkout: '.$problem->getMessage());

            throw new CheckoutRefused(__('Paystack could not start the payment just now. Try again in a few minutes.'));
        }
    }

    private function openInvoice(string $number): PlatformInvoice
    {
        $invoice = PlatformInvoice::query()->where('kind', InvoiceKind::Subscription)->where('number', $number)->first();

        if (! $invoice instanceof PlatformInvoice) {
            throw new CheckoutRefused(__('That invoice was not found.'));
        }

        if ($invoice->status !== InvoiceStatus::Open) {
            throw new CheckoutRefused(__('Invoice :number is already paid.', ['number' => $invoice->number]));
        }

        return $invoice;
    }

    private function askAboutPendingAttempts(PlatformInvoice $invoice): void
    {
        $pending = PlatformPayment::query()
            ->where('platform_invoice_id', $invoice->id)
            ->where('method', PlatformPayment::PAYSTACK)
            ->where('status', PlatformPaymentStatus::Pending)
            ->pluck('paystack_reference');

        foreach ($pending as $reference) {
            try {
                $this->settlement->settle((string) $reference);
            } catch (PaystackUnavailable) {
                throw new CheckoutRefused(__('Paystack could not be asked about your earlier payment just now. Try again in a few minutes.'));
            }
        }

        if (PlatformInvoice::query()->findOrFail($invoice->id)->status !== InvoiceStatus::Open) {
            throw new CheckoutRefused(__('Invoice :number is already paid.', ['number' => $invoice->number]));
        }
    }
}
