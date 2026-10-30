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
}
