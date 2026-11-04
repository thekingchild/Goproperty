<?php

declare(strict_types=1);

namespace App\Dashboards;

use Carbon\CarbonImmutable;

/**
 * Where an organization's dashboard figures stand (P08-04): a token that
 * changes whenever a record they are worked out from changes, and when it
 * last changed. A cached figure is good only while it carries the current
 * token (DashboardCache). See ARCHITECTURE.md § 13.
 */
final readonly class DashboardGeneration
{
    public function __construct(
        public string $token,
        public int $changedAt,
    ) {}

    public static function fresh(): self
    {
        return new self(bin2hex(random_bytes(12)), CarbonImmutable::now()->getTimestamp());
    }

    /** The generation as stored in the cache (arrays only: the cache unserializes no objects). */
    public static function fromStored(mixed $stored): ?self
    {
        if (! is_array($stored) || ! is_string($stored['token'] ?? null) || $stored['token'] === '' || ! is_int($stored['at'] ?? null)) {
            return null;
        }

        return new self($stored['token'], $stored['at']);
    }

    /** @return array{token: string, at: int} */
    public function toStored(): array
    {
        return ['token' => $this->token, 'at' => $this->changedAt];
    }

    /** Seconds since the figures' records last changed. */
    public function age(): int
    {
        return max(0, CarbonImmutable::now()->getTimestamp() - $this->changedAt);
    }
}
