<?php

declare(strict_types=1);

namespace App\Platform\Operations\Health;

use Carbon\CarbonImmutable;

/**
 * One Paystack account's webhook deliveries over a period (PLATFORM_CONSOLE.md
 * § 2 "Webhooks"): GoProperty's own, or one agency's. A delivery failed when
 * its signature did not match or its processing ended in an error; it awaits
 * processing when it was signed correctly and not processed yet.
 * Failure rate = failed ÷ received, in whole percent, rounded down.
 */
final readonly class WebhookFigures
{
    public function __construct(
        public ?int $organizationId,
        public string $name,
        public int $received,
        public int $failed,
        public int $awaiting,
        public ?CarbonImmutable $lastReceivedAt,
        public ?string $connectionStatus = null,
    ) {}

    public function failurePercent(): ?int
    {
        return $this->received === 0 ? null : intdiv($this->failed * 100, $this->received);
    }

    public function rateLabel(): string
    {
        $percent = $this->failurePercent();

        return $percent === null ? '—' : $percent.'%';
    }
}
