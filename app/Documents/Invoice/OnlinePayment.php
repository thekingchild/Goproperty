<?php

declare(strict_types=1);

namespace App\Documents\Invoice;

/**
 * The "Pay online" button and short link (BRANDING_DOCUMENTS.md § 2 item
 * 6): only when the organization has connected its own Paystack account
 * and the amount is within its online limit, which the issuing module
 * decides. $url is the branded pay page (/pay/{reference}, P03-05c).
 */
final readonly class OnlinePayment
{
    public function __construct(
        public string $url,
    ) {}

    /** The link as printed, without the scheme. */
    public function shortLink(): string
    {
        return (string) preg_replace('#^https?://#', '', $this->url);
    }
}
