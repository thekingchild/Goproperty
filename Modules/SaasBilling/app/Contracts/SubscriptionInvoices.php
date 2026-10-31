<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

/**
 * The current organization's subscription invoices (P06-02), for Core's
 * Settings → Subscription page. Reads only; the page's policy decides who
 * sees them. Bound in SaasBillingServiceProvider.
 */
interface SubscriptionInvoices
{
    /** @return list<SubscriptionInvoice> the newest first */
    public function recent(int $limit = 12): array;

    /** One of the current organization's subscription invoices with its lines (the console's invoice page, P06-07b); null when it has none with this id. */
    public function find(int $invoiceId): ?SubscriptionInvoice;
}
