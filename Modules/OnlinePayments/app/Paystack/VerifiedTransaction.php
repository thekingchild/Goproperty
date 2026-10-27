<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Paystack;

use Carbon\CarbonImmutable;

/**
 * A transaction as Paystack reports it when asked with the organization's
 * secret key (GET /transaction/verify/{reference}), never as a webhook
 * said it (BILLING_PAYMENTS.md § 4.2): `status` is Paystack's (success,
 * failed, abandoned, ongoing, pending, reversed, …), `amount` in kobo as
 * an integer, `domain` test or live.
 */
final readonly class VerifiedTransaction
{
    public function __construct(
        public string $reference,
        public string $status,
        public int $amount,
        public string $currency,
        public string $domain,
        public ?CarbonImmutable $paidAt,
        public ?string $channel,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === 'success';
    }

    /** Paystack says the money did not (or no longer) arrive: the attempt is over. */
    public function failed(): bool
    {
        return in_array($this->status, ['failed', 'reversed'], true);
    }
}
