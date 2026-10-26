<?php

declare(strict_types=1);

namespace App\Hosting;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Last-run markers for background work, read by `goproperty:hosting`, the
 * installer's live cron check and the console's operations dashboard
 * (docs/runbooks/shared-hosting.md § 5). Kept in the cache: losing one on
 * `cache:clear` only means it is rewritten on the next cron minute.
 */
final readonly class Heartbeats
{
    public const string SCHEDULER = 'scheduler';

    public const string QUEUE_TICK = 'queue-tick';

    public function __construct(private Repository $cache) {}

    /** @param  array<string, mixed>  $details */
    public function beat(string $name, array $details = []): void
    {
        $this->cache->forever($this->key($name), [
            'at' => CarbonImmutable::now()->toIso8601String(),
            ...$details,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function last(string $name): ?array
    {
        $value = $this->cache->get($this->key($name));

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    public function lastAt(string $name): ?CarbonImmutable
    {
        $at = $this->last($name)['at'] ?? null;

        return is_string($at) ? CarbonImmutable::parse($at) : null;
    }

    private function key(string $name): string
    {
        return 'hosting:heartbeat:'.$name;
    }
}
