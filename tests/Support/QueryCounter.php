<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Counts the SQL a request runs (P09-03's lasting guards against N+1
 * queries on the hot paths): a page must run the same number of queries
 * for a few rows as for many. Sessions, the cache, rate limits and the
 * queue tick vary with what earlier requests warmed, so they are left out.
 */
final class QueryCounter
{
    /** Tables whose queries depend on warm-up, not on the page's rows. */
    private const string NOISE = '/\b(?:from|into|update)\s+`?(?:sessions|cache|cache_locks|jobs|failed_jobs|job_batches|system_heartbeats)`?/i';

    /**
     * @param  Closure(): mixed  $request
     * @return list<string> the queries the request ran, warm-up tables left out
     */
    public static function queries(Closure $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $request();
        } finally {
            DB::disableQueryLog();
        }

        $queries = array_map(static fn (array $query): string => (string) $query['query'], DB::getQueryLog());
        DB::flushQueryLog();

        return array_values(array_filter($queries, static fn (string $sql): bool => preg_match(self::NOISE, $sql) !== 1));
    }

    /** @param  Closure(): mixed  $request */
    public static function count(Closure $request): int
    {
        return count(self::queries($request));
    }
}
