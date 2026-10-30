<?php

declare(strict_types=1);

namespace App\Platform\Deliveries\Health;

/**
 * What SMS cost in one period, across organizations (P05-05;
 * PLATFORM_CONSOLE.md § 2 "SMS spend").
 *
 * - From `notification_deliveries` (SMS queued in the period that GoText
 *   took): messages, pages and GoText's cost, each as far as GoText
 *   reported it (`pages`, `cost_kobo` stay empty when it does not).
 * - From the credit ledger (`sms_credit_entries` written in the period):
 *   credits taken for sends, given back for messages GoText refused, bought
 *   and granted as monthly allowance.
 */
final readonly class SmsSpend
{
    public function __construct(
        public DeliveryWindow $window,
        public int $messages,
        public int $pages,
        public int $pagesUnreported,
        public int $costKobo,
        public int $costUnreported,
        public int $creditsTaken,
        public int $creditsGivenBack,
        public int $creditsBought,
        public int $allowanceGranted,
    ) {}

    /** Credits taken for sends, less those given back. */
    public function creditsUsed(): int
    {
        return $this->creditsTaken - $this->creditsGivenBack;
    }

    /** GoText reported the cost of every message sent. */
    public function costComplete(): bool
    {
        return $this->costUnreported === 0;
    }
}
