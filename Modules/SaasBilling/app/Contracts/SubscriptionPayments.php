<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use App\Models\User;

/**
 * Paying a subscription invoice by card through GoProperty's own Paystack
 * checkout (P06-03; BILLING_PAYMENTS.md § 4), for Core's Settings →
 * Subscription page. The invoice is marked paid only once Paystack confirms
 * the payment (the return from checkout, the webhook or the hourly
 * reconciliation, verified by fetch), once per payment. Works in the
 * current organization. Bound in SaasBillingServiceProvider.
 */
interface SubscriptionPayments
{
    /** Whether invoices can be paid online here now (GoProperty's Paystack is set up). */
    public function available(): bool;

    /**
     * Starts paying an open subscription invoice and returns Paystack's
     * checkout address. Authorization is the caller's (SubscriptionPolicy::pay).
     *
     * @throws CheckoutRefused
     */
    public function start(User $payer, string $invoiceNumber, string $returnUrl): string;
}
