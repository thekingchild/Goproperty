<?php

declare(strict_types=1);

namespace App\Hosting;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * How live screens (Building Group, ticket threads) stay current: Livewire
 * polling on shared hosting, Reverb WebSockets with a slow poll as fallback
 * on a VPS (ARCHITECTURE.md § 12.1).
 */
final readonly class Realtime
{
    public function __construct(
        public RealtimeDriver $driver,
        private int $pollSeconds,
        private int $fallbackPollSeconds,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        $driver = $config->get('hosting.realtime.driver');

        return new self(
            RealtimeDriver::tryFrom(is_string($driver) ? $driver : '')
                ?? throw new InvalidArgumentException('REALTIME_DRIVER must be "polling" or "reverb".'),
            max(1, self::int($config->get('hosting.realtime.poll_seconds'), 15)),
            max(1, self::int($config->get('hosting.realtime.fallback_poll_seconds'), 60)),
        );
    }

    public function usesWebsockets(): bool
    {
        return $this->driver === RealtimeDriver::Reverb;
    }

    /** Seconds between Livewire polls (`wire:poll.{n}s`) on live screens. */
    public function pollSeconds(): int
    {
        return $this->usesWebsockets() ? $this->fallbackPollSeconds : $this->pollSeconds;
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
