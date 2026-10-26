<?php

declare(strict_types=1);

namespace App\Hosting\Queue;

use App\Hosting\Heartbeats;
use Carbon\CarbonImmutable;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Queue\WorkerOptions;
use RuntimeException;

/**
 * Drains the database queue inside the cron's `schedule:run` process, so
 * shared hosting needs one cron line and no `exec`/`proc_open`
 * (INSTALLER.md § 8). Scheduled from routes/console.php every minute.
 *
 * Lanes are visited in turn, one job per lane per pass, until every lane is
 * empty or capped, the time budget is spent, memory runs high, or the
 * database connection drops. A lock keeps two ticks from overlapping.
 */
final readonly class QueueTick
{
    private const string LOCK = 'hosting:queue-tick';

    public function __construct(
        private TickWorker $worker,
        private Cache $cache,
        private Heartbeats $heartbeats,
        private Config $config,
    ) {}

    public function run(): TickResult
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The queue tick needs a cache store that supports locks (database, file or redis).');
        }

        $lock = $store->lock(self::LOCK, $this->setting('lock_seconds', 120));

        if (! $lock->get()) {
            return new TickResult(TickStopReason::Locked);
        }

        try {
            $result = $this->drain();
            $this->heartbeats->beat(Heartbeats::QUEUE_TICK, $result->toArray());

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function drain(): TickResult
    {
        $startedAt = hrtime(true);
        $deadline = CarbonImmutable::now()->addSeconds($this->setting('budget_seconds', 45));
        $connection = $this->connection();
        $memoryMb = $this->setting('memory_mb', 192);
        $options = new WorkerOptions(
            name: 'queue-tick',
            backoff: $this->setting('backoff', 30),
            memory: $memoryMb,
            timeout: $this->setting('job_timeout', 50),
            sleep: 0,
            maxTries: $this->setting('tries', 3),
            stopWhenEmpty: true,
        );

        $active = $this->lanes();
        $processed = array_fill_keys(array_keys($active), 0);
        $stoppedBy = TickStopReason::Drained;

        while ($active !== []) {
            foreach ($active as $name => $lane) {
                if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                    $stoppedBy = TickStopReason::Budget;
                    break 2;
                }

                if ($this->worker->memoryExceeded($memoryMb)) {
                    $stoppedBy = TickStopReason::Memory;
                    break 2;
                }

                if (! $this->worker->runNext($connection, $lane['queues'], $options)) {
                    unset($active[$name]);

                    continue;
                }

                $processed[$name]++;

                if ($this->worker->lostConnection) {
                    $stoppedBy = TickStopReason::LostConnection;
                    break 2;
                }

                if ($processed[$name] >= $lane['max_jobs']) {
                    unset($active[$name]);
                }
            }
        }

        return new TickResult(
            $stoppedBy,
            $processed,
            (int) round((hrtime(true) - $startedAt) / 1_000_000),
        );
    }

    /** @return array<string, array{queues: string, max_jobs: int}> */
    private function lanes(): array
    {
        $lanes = $this->config->get('hosting.queue_tick.lanes');

        if (! is_array($lanes) || $lanes === []) {
            throw new RuntimeException('hosting.queue_tick.lanes must list at least one lane.');
        }

        $parsed = [];

        foreach ($lanes as $name => $lane) {
            $queues = is_array($lane) ? ($lane['queues'] ?? null) : null;
            $maxJobs = is_array($lane) ? ($lane['max_jobs'] ?? null) : null;

            if (! is_string($name) || ! is_array($queues) || $queues === [] || ! is_int($maxJobs) || $maxJobs < 1) {
                throw new RuntimeException(sprintf('Queue tick lane "%s" needs a list of queues and a max_jobs of 1 or more.', $name));
            }

            $parsed[$name] = ['queues' => implode(',', array_map(strval(...), $queues)), 'max_jobs' => $maxJobs];
        }

        return $parsed;
    }

    private function connection(): string
    {
        $connection = $this->config->get('hosting.queue_tick.connection');

        if (! is_string($connection) || $this->config->get("queue.connections.{$connection}.driver") !== 'database') {
            throw new RuntimeException('The queue tick drains a "database" queue connection only (QUEUE_TICK_CONNECTION).');
        }

        return $connection;
    }

    private function setting(string $key, int $default): int
    {
        $value = $this->config->get("hosting.queue_tick.{$key}");

        return is_numeric($value) ? (int) $value : $default;
    }
}
