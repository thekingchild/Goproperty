<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use App\Models\User;

/**
 * Buying SMS credit bundles in the app (P05-03; BILLING_PAYMENTS.md § 1.2),
 * for Core's Settings → SMS credits page: the bundles on offer (priced by
 * finance-admin in the console, VAT on top), a checkout through
 * GoProperty's own Paystack account, and the latest purchases. The
 * credits are added only once Paystack confirms the payment (the return
 * from checkout or the webhook, verified by fetch), once per payment.
 * Works in the current organization. Bound in SaasBillingServiceProvider.
 */
interface SmsCreditCheckout
{
    /** Whether bundles can be bought online here now (GoProperty's Paystack is set up). */
    public function available(): bool;

    /** @return list<SmsCreditBundle> */
    public function bundles(): array;

    /**
     * Starts paying for a bundle and returns Paystack's checkout address.
     * Authorization is the caller's (SmsCreditPolicy::buy).
     *
     * @throws CheckoutRefused
     */
    public function start(User $buyer, int $credits, string $returnUrl): string;

    /** @return list<SmsCreditPurchase> the newest first */
    public function purchases(int $limit = 10): array;
}
