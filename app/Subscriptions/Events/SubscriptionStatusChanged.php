<?php

declare(strict_types=1);

namespace App\Subscriptions\Events;

use App\Enums\SubscriptionStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A subscription moved between lifecycle states (P06-01b; BILLING_PAYMENTS.md
 * § 2), dispatched once the change is committed. P06-02 issues the first
 * invoice when a trial ends (`trialing` → `active`); P06-05's dunning and
 * the notices listen too. $from is null when the subscription was created.
 */
final readonly class SubscriptionStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $organizationId,
        public int $subscriptionId,
        public ?SubscriptionStatus $from,
        public SubscriptionStatus $to,
    ) {}
}
