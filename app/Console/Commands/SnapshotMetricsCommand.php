<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Analytics\MetricsSnapshot;
use App\Platform\Analytics\SnapshotRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * The platform analytics snapshot by hand (P08-02; ARCHITECTURE.md § 7):
 * the scheduler runs SnapshotRunner::nightly() every hour at :10 through
 * Schedule::call (no shell on shared hosting). With a date, that Lagos day's
 * flows are worked out again (and its stocks as they stand now when it is
 * yesterday). Safe to re-run: the day's rows are replaced, never added to.
 */
final class SnapshotMetricsCommand extends Command
{
    protected $signature = 'metrics:snapshot {date? : A Lagos day that has ended (YYYY-MM-DD); without it, what the nightly run would do}';

    protected $description = 'Take the platform analytics snapshot (daily_platform_metrics) for the System analytics page';

    public function handle(SnapshotRunner $runner): int
    {
        $date = $this->argument('date');

        if (! is_string($date) || $date === '') {
            $days = $runner->nightly();
            $this->components->info($days === [] ? 'Nothing to do: yesterday is already taken.' : 'Taken: '.implode(', ', $days).'.');

            return self::SUCCESS;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, MetricsSnapshot::TIMEZONE);

            if (! $day instanceof CarbonImmutable || $day->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException("\"{$date}\" is not a date (YYYY-MM-DD).");
            }

            $rows = $runner->day($day);
        } catch (Throwable $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Wrote {$rows} figures for {$date}.");

        return self::SUCCESS;
    }
}
