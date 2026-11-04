<?php

declare(strict_types=1);

namespace App\Platform\Operations\Health;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Counts reported exceptions per minute in the cache (PLATFORM_CONSOLE.md
 * § 2 "App" error rate and the "error spike" alert), without Sentry: each
 * exception the handler reports (not 404s, validation or other ignored
 * ones) adds one to its minute. Kept two hours. Counting never throws: a
 * cache that is down must not stop the report itself.
 */
final readonly class ErrorCounter
{
    private const string KEY = 'operations:errors:';

    public function __construct(private Cache $cache) {}

    public function record(?CarbonImmutable $at = null): void
    {
        try {
            $at ??= CarbonImmutable::now();
            $key = self::KEY.$at->utc()->format('YmdHi');
            $this->cache->add($key, 0, $at->addHours(2));
            $this->cache->increment($key);
        } catch (Throwable) {
            // Nothing to do: the exception is still reported.
        }
    }

    /** Exceptions reported in the last $minutes minutes, this minute included. */
    public function lastMinutes(int $minutes, CarbonImmutable $now): int
    {
        $keys = [];

        for ($minute = 0; $minute < $minutes; $minute++) {
            $keys[] = self::KEY.$now->utc()->subMinutes($minute)->format('YmdHi');
        }

        try {
            return array_sum(array_map(static fn (mixed $count): int => is_numeric($count) ? (int) $count : 0, $this->cache->many($keys)));
        } catch (Throwable) {
            return 0;
        }
    }
}
