<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Paystack;

use Carbon\CarbonImmutable;

/**
 * A transaction as Paystack reports it when asked with the organization's
 * secret key (GET /transaction/verify/{reference}), never as a webhook
 * said it (BILLING_PAYMENTS.md § 4.2): `status` is Paystack's (success,
 * failed, abandoned, ongoing, pending, reversed, …), `amount` in kobo as
 * an integer, `domain` test or live. When the Paystack account passes its
 * fees to the payer, `amount` is what the payer was charged,
 * `requestedAmount` what GoProperty asked for and `fees` Paystack's fee.
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
        public ?int $requestedAmount = null,
        public ?int $fees = null,
    ) {}

    /**
     * Paystack's fee, when the payer paid exactly the amount asked plus
     * Paystack's fee on top (the account passes its fees to the payer):
     * Paystack keeps the fee and settles the amount asked. Null for any
     * other amount.
     */
    public function feeOnTop(int $asked): ?int
    {
        if ($this->requestedAmount !== $asked || $this->fees === null || $this->fees <= 0) {
            return null;
        }

        return $this->amount === $asked + $this->fees ? $this->fees : null;
    }

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
