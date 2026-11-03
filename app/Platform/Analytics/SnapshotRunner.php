<?php

declare(strict_types=1);

namespace App\Platform\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Runs the nightly platform analytics snapshot (`metrics-snapshot`, every
 * hour at :10; ARCHITECTURE.md § 7). The first run after the Lagos day ends
 * takes that day's flows and stocks; later runs find it done and stop, so
 * a missed night is made up within the hour. Days missed for longer (the
 * site was down) get their flows back, up to CATCH_UP_DAYS: what happened
 * is in the records, but the stocks as they stood then are not, so those
 * days keep none (the dashboard reads the nearest day that has them).
 *
 * Never two at once (a cache lock); each day is written in one
 * transaction (MetricsSnapshot), so a run that dies leaves the day as it
 * was and the next hour takes it again.
 */
final readonly class SnapshotRunner
{
    public const int CATCH_UP_DAYS = 7;

    public function __construct(private MetricsSnapshot $snapshot) {}

    /** @return list<string> the days written, oldest first */
    public function nightly(): array
    {
        $lock = Cache::lock('platform-metrics-snapshot', 600);

        if (! $lock->get()) {
            return [];
        }

        try {
            $yesterday = CarbonImmutable::now(MetricsSnapshot::TIMEZONE)->startOfDay()->subDay();
            $first = DB::table('daily_platform_metrics')->where('metric', PlatformMetric::Snapshot->value)->min('date');
            $taken = DB::table('daily_platform_metrics')
                ->where('metric', PlatformMetric::Snapshot->value)
                ->where('date', '>=', $yesterday->subDays(self::CATCH_UP_DAYS)->toDateString())
                ->get(['date', 'dimension'])
                ->groupBy(static fn (object $row): string => substr((string) $row->date, 0, 10))
                ->map(static fn ($rows): array => $rows->pluck('dimension')->all());
            $written = [];

            // Missed days after the first snapshot ever: flows only.
            if ($first !== null) {
                for ($day = $yesterday->subDays(self::CATCH_UP_DAYS); $day->lessThan($yesterday); $day = $day->addDay()) {
                    $date = $day->toDateString();

                    if ($date > substr((string) $first, 0, 10) && ! in_array('flows', $taken->get($date, []), true)) {
                        $this->snapshot->take($day, withStocks: false);
                        $written[] = $date;
                    }
                }
            }

            if (! in_array('stocks', $taken->get($yesterday->toDateString(), []), true)) {
                $this->snapshot->take($yesterday, withStocks: true);
                $written[] = $yesterday->toDateString();
            }

            return $written;
        } finally {
            $lock->release();
        }
    }

    /**
     * By hand (`php artisan metrics:snapshot`): the day's flows again, and
     * its stocks as they stand now only when it is yesterday.
     */
    public function day(CarbonImmutable $day): int
    {
        $yesterday = CarbonImmutable::now(MetricsSnapshot::TIMEZONE)->startOfDay()->subDay();

        return $this->snapshot->take($day, withStocks: $day->toDateString() === $yesterday->toDateString());
    }
}
