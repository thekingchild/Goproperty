<?php

declare(strict_types=1);

namespace Modules\SaasBilling\SmsCredits;

use App\Marketing\PlanCatalogue;
use App\Models\User;
use App\Settings\SettingsStore;
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
use Modules\SaasBilling\Payments\PlatformPaymentSettlement;
use Modules\SaasBilling\Paystack\PaystackUnavailable;
use Modules\SaasBilling\Paystack\PlatformPaystack;

/**
 * SMS credit bundles bought in the app (P05-03; BILLING_PAYMENTS.md § 1.2,
 * § 4): the only writer of `platform_invoices` and `platform_payments` so
 * far. A bundle is a platform invoice (`PR-2026-000123`: credits × the
 * console's price per credit, plus 7.5 % VAT) paid through GoProperty's own
 * Paystack checkout (reference `PINV-{number}-a1`).
 *
 * settle() runs on the return from checkout and on Paystack's webhook
 * (Payments\PlatformPaymentSettlement since P06-03). It never trusts either: it asks Paystack (verify by fetch, § 4.2) and only a
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
        private PlatformPaymentSettlement $settlement,
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
            $vat = $subtotal->percentage($this->plans->vatBasisPoints());

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
            $this->settlement->fail($payment->id, 'Paystack did not start the checkout: '.$problem->getMessage());

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
     * what Paystack says about it now (Payments\PlatformPaymentSettlement,
     * shared with subscription invoices since P06-03).
     *
     * @throws PaystackUnavailable when Paystack cannot be asked now (nothing was recorded)
     */
    public function settle(string $reference): PaymentOutcome
    {
        return $this->settlement->settle($reference);
    }
}
