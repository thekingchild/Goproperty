<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\PayOnline;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Modules\Billing\Contracts\PayableBill;
use Modules\Billing\Contracts\PaymentOption;
use Modules\OnlinePayments\Transactions\PaystackTransactions;

/**
 * "Pay online" on a bill's pay page (`/pay/{reference}`) and invoice
 * (Billing's PaymentOptions slot, P03-05c): offered while the
 * organization's own Paystack is connected and active and what the bill
 * still owes is within the organization's online-payment cap
 * (ONBOARDING_COMPLIANCE.md § 4.2: above it, bank details only). Asked
 * inside the bill's organization.
 *
 * The button links to the module's own page (Http\Controllers\PayOnlineController)
 * through a signed address that names the organization, so the page finds
 * the bill through Billing's contract without reading Billing's tables. A
 * link, not a form: the pay page's security policy only lets its forms post
 * to itself.
 */
final readonly class PaystackOption implements PaymentOption
{
    /** How long the button's signed address works: the pay page is never cached, so a fresh one comes with each view. */
    public const int LINK_HOURS = 12;

    /** Billing keeps the option for the process's life: what it needs is resolved per call, in the bill's organization. */
    public function offers(PayableBill $bill): bool
    {
        return app(PaystackTransactions::class)->offers($bill);
    }

    public function render(PayableBill $bill): Htmlable
    {
        return new HtmlString(view('onlinepayments::pay.option', [
            'url' => self::link($bill->paymentReference, $bill->organizationId),
            'amount' => $bill->outstanding->format(),
        ])->render());
    }

    /** The signed address of the bill's "pay online" page. */
    public static function link(string $paymentReference, int $organizationId): string
    {
        return URL::temporarySignedRoute('online-payments.pay', now()->addHours(self::LINK_HOURS), [
            'reference' => $paymentReference,
            'organization' => $organizationId,
        ]);
    }
}
