<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

/**
 * Replaying a delivery to GoProperty's own Paystack webhook (P06-03;
 * PLATFORM_CONSOLE.md § 3 "replay button"), for the console's Paystack
 * webhooks page. Only a delivery whose signature matched is processed
 * again; processing asks Paystack first and settles a payment at most once,
 * so a replay never pays an invoice twice. Authorization and the audit
 * entry are the caller's (`webhooks.replay`). Bound in
 * SaasBillingServiceProvider.
 */
interface PlatformWebhookReplays
{
    /** Queues the delivery's processing again; false when it may not be replayed (unsigned, or not GoProperty's own). */
    public function replay(int $webhookLogId): bool;
}
