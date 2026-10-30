<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Paystack;

/**
 * A transaction as GoProperty's Paystack account reports it when asked
 * (GET /transaction/verify/{reference}), never as a webhook said it
 * (BILLING_PAYMENTS.md § 4.2): `status` is Paystack's (success, failed,
 * abandoned, …), `amount` in kobo, `domain` test or live.
 */
final readonly class VerifiedCharge
{
    public function __construct(
        public string $reference,
        public string $status,
        public int $amount,
        public string $currency,
        public string $domain,
        public ?string $channel,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Paystack says the money did not (or no longer) arrive. Not
     * "abandoned": the payer may still finish that checkout.
     */
    public function failed(): bool
    {
        return in_array($this->status, ['failed', 'reversed'], true);
    }
}
