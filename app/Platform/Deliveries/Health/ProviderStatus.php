<?php

declare(strict_types=1);

namespace App\Platform\Deliveries\Health;

use Carbon\CarbonImmutable;

/**
 * How one provider of one channel is doing (P05-05; PLATFORM_CONSOLE.md
 * § 2 "provider status"), from the deliveries of the last 7 days: the
 * last message it took, the last failure with its error (numbers masked,
 * never the message), when a delivery report was last asked for (GoText
 * is polled, it has no webhook), and the health word from the attempts of
 * the last 24 hours.
 */
final readonly class ProviderStatus
{
    public function __construct(
        public string $channel,
        public string $provider,
        public int $attempted,
        public int $problems,
        public ?CarbonImmutable $lastSentAt,
        public ?CarbonImmutable $lastFailureAt,
        public ?string $lastFailureError,
        public ?CarbonImmutable $lastStatusCheckAt,
    ) {}

    public function health(): ProviderHealth
    {
        return ProviderHealth::judge($this->problems, $this->attempted);
    }

    public function failureRate(): ?string
    {
        return ChannelFigures::rate($this->problems, $this->attempted);
    }

    public function label(): string
    {
        return match ($this->provider) {
            'gotext' => 'GoText',
            'meta' => 'Meta (each agency\'s own WhatsApp)',
            'log' => 'Log only (no real messages)',
            default => ucfirst($this->provider),
        };
    }
}
