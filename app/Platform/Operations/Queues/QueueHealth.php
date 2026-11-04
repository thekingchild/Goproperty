<?php

declare(strict_types=1);

namespace App\Platform\Operations\Queues;

use App\Platform\Deliveries\Health\MessagingHealth;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Queues for the operations dashboard (PLATFORM_CONSOLE.md § 2 "Queues"):
 * depth and wait per queue and the failed jobs. On shared hosting the
 * queue is the `jobs` table, read with one grouped query; other drivers
 * (Redis on a VPS, Horizon's own screen beside it) answer through the
 * queue's size methods. Read-only.
 */
final readonly class QueueHealth
{
    /** The queue names of ARCHITECTURE.md § 4 "Queues", always listed. */
    public const array QUEUES = ['billing', 'notifications', 'default', 'pdf', 'media', 'imports'];

    public function __construct(private Config $config) {}

    public function connection(): string
    {
        $connection = $this->config->get('queue.default');

        return is_string($connection) ? $connection : 'sync';
    }

    public function driver(): string
    {
        $driver = $this->config->get('queue.connections.'.$this->connection().'.driver');

        return is_string($driver) ? $driver : 'sync';
    }

    /** False for `sync`: jobs run inline and nothing waits. */
    public function queues(): bool
    {
        return $this->driver() !== 'sync';
    }

    /** @return list<QueueFigures> the known queues first, then any other found */
    public function figures(CarbonInterface $now): array
    {
        return match ($this->driver()) {
            'sync' => [],
            'database' => $this->fromTable($now),
            default => $this->fromDriver(),
        };
    }

    public function failedCount(?CarbonInterface $since = null): int
    {
        return $this->failedTable()
            ->when($since !== null, fn ($query) => $query->where('failed_at', '>=', $since))
            ->count();
    }

    /** @return list<FailedJob> newest first */
    public function recentFailures(int $limit = 10): array
    {
        return array_values($this->failedTable()
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(static function (object $row): FailedJob {
                $payload = json_decode(is_string($row->payload) ? $row->payload : '', true);
                $job = is_array($payload) && is_string($payload['displayName'] ?? null) ? $payload['displayName'] : 'Unknown job';
                $firstLine = is_string($row->exception) ? strtok($row->exception, "\n") : false;

                return new FailedJob(
                    uuid: (string) $row->uuid,
                    queue: (string) $row->queue,
                    job: class_basename($job),
                    failedAt: is_string($row->failed_at) ? CarbonImmutable::parse($row->failed_at) : null,
                    error: is_string($firstLine) ? MessagingHealth::maskNumbers(self::maskEmails($firstLine)) : null,
                );
            })
            ->all());
    }

    /** @return list<QueueFigures> */
    private function fromTable(CarbonInterface $now): array
    {
        $connection = $this->config->get('queue.connections.'.$this->connection());
        $connection = is_array($connection) ? $connection : [];
        $time = $now->getTimestamp();

        // Aliases avoid reserved words (DELAYED is one in MySQL and MariaDB).
        $rows = DB::connection(is_string($connection['connection'] ?? null) ? $connection['connection'] : null)
            ->table(is_string($connection['table'] ?? null) ? $connection['table'] : 'jobs')
            ->groupBy('queue')
            ->select('queue')
            ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 ELSE 0 END) AS waiting_jobs', [$time])
            ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at > ? THEN 1 ELSE 0 END) AS delayed_jobs', [$time])
            ->selectRaw('SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS running_jobs')
            ->selectRaw('MIN(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN available_at END) AS oldest_available', [$time])
            ->get()
            ->keyBy('queue');

        $figures = [];

        foreach ([...self::QUEUES, ...$rows->keys()->map(strval(...))->all()] as $queue) {
            $row = $rows->get($queue);
            $figures[$queue] ??= new QueueFigures(
                queue: $queue,
                waiting: (int) ($row->waiting_jobs ?? 0),
                delayed: (int) ($row->delayed_jobs ?? 0),
                running: (int) ($row->running_jobs ?? 0),
                oldestWaitingSince: is_numeric($row->oldest_available ?? null) ? CarbonImmutable::createFromTimestamp((int) $row->oldest_available) : null,
            );
        }

        return array_values($figures);
    }

    /** @return list<QueueFigures> */
    private function fromDriver(): array
    {
        $figures = [];

        foreach (self::QUEUES as $queue) {
            try {
                $driver = Queue::connection($this->connection());
                $oldest = $driver->creationTimeOfOldestPendingJob($queue);
                $figures[] = new QueueFigures(
                    queue: $queue,
                    waiting: $driver->pendingSize($queue),
                    delayed: $driver->delayedSize($queue),
                    running: $driver->reservedSize($queue),
                    oldestWaitingSince: is_numeric($oldest) ? CarbonImmutable::createFromTimestamp((int) $oldest) : null,
                );
            } catch (Throwable) {
                $figures[] = new QueueFigures($queue, null, null, null, null);
            }
        }

        return $figures;
    }

    private function failedTable(): Builder
    {
        $database = $this->config->get('queue.failed.database');
        $table = $this->config->get('queue.failed.table');

        return DB::connection(is_string($database) ? $database : null)->table(is_string($table) ? $table : 'failed_jobs');
    }

    private static function maskEmails(string $text): string
    {
        return (string) preg_replace('/[^\s@"\'<>]+@[^\s@"\'<>]+\.[A-Za-z]{2,}/', '[email]', $text);
    }
}
