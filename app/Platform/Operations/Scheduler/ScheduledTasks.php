<?php

declare(strict_types=1);

namespace App\Platform\Operations\Scheduler;

use App\Hosting\Heartbeats;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Every scheduled task with its last run and result (PLATFORM_CONSOLE.md
 * § 2 "Scheduler"). The tasks are defined in routes/console.php, which only
 * the command line loads, so the scheduler's own minute check
 * (`operations-alerts`) writes the list into the cache for the console's
 * pages to read. Each run, failure and skip is recorded as a heartbeat
 * `task:<name>` by the schedule's events (OperationsServiceProvider).
 * Both live in the cache: after `cache:clear` they are rebuilt within the
 * minute. A task is judged missed only for a run due after the list first
 * saw it and while the scheduler had been running without a gap (a
 * scheduler back from a stop is one alert, not one per task).
 */
final readonly class ScheduledTasks
{
    public const string CATALOGUE = 'operations:schedule';

    /** A task is missed when it has not run this long after it was due. */
    public const int MISSED_AFTER_MINUTES = 15;

    /** A gap this long between two captures means the scheduler had stopped. */
    private const int GAP_MINUTES = 3;

    public function __construct(private Cache $cache, private Heartbeats $heartbeats) {}

    public static function nameOf(Event $event): string
    {
        $name = $event->description;

        return is_string($name) && $name !== '' ? $name : $event->getSummaryForDisplay();
    }

    /** Called from the schedule: the tasks it defines, kept with when each was first seen. */
    public function capture(Schedule $schedule, CarbonImmutable $now): void
    {
        $known = $this->catalogue();
        $state = $this->state();
        $runningSince = $state['captured_at'] !== null && $state['running_since'] !== null && $state['captured_at']->diffInMinutes($now, true) <= self::GAP_MINUTES
            ? $state['running_since']
            : $now;
        $tasks = [];

        foreach ($schedule->events() as $event) {
            $name = self::nameOf($event);
            $tasks[$name] = [
                'expression' => $event->expression,
                'timezone' => is_string($event->timezone) ? $event->timezone : (string) config('app.timezone'),
                'first_seen' => $known[$name]['first_seen'] ?? $now->toIso8601String(),
            ];
        }

        $this->cache->forever(self::CATALOGUE, ['tasks' => $tasks, 'running_since' => $runningSince->toIso8601String(), 'captured_at' => $now->toIso8601String()]);
    }

    /** @param  'ok'|'failed'|'skipped'  $result */
    public function record(Event $event, string $result, ?Throwable $exception = null, ?float $seconds = null): void
    {
        $this->heartbeats->beat('task:'.self::nameOf($event), array_filter([
            'result' => $result,
            'seconds' => $seconds,
            // The class only: a message may name a customer (AGENT.md § 5, no PII in logs).
            'error' => $exception !== null ? class_basename($exception) : null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /** @return list<TaskStatus> in the schedule's order */
    public function all(CarbonImmutable $now): array
    {
        $runningSince = $this->state()['running_since'];
        $statuses = [];

        foreach ($this->catalogue() as $name => $task) {
            $statuses[] = $this->status($name, $task, $now, $runningSince);
        }

        return $statuses;
    }

    /** @param  array{expression: string, timezone: string, first_seen: string}  $task */
    private function status(string $name, array $task, CarbonImmutable $now, ?CarbonImmutable $runningSince): TaskStatus
    {
        $last = $this->heartbeats->last('task:'.$name) ?? [];
        $lastAt = is_string($last['at'] ?? null) ? CarbonImmutable::parse($last['at']) : null;
        $result = is_string($last['result'] ?? null) ? $last['result'] : null;
        $error = is_string($last['error'] ?? null) ? $last['error'] : null;
        $next = null;
        $dueBefore = null;

        try {
            $cron = new CronExpression($task['expression']);
            $local = $now->setTimezone($task['timezone']);
            $next = CarbonImmutable::instance($cron->getNextRunDate($local))->utc();
            // The run that should have happened by now, allowing for a busy minute.
            $dueBefore = CarbonImmutable::instance($cron->getPreviousRunDate($local->subMinutes(self::MISSED_AFTER_MINUTES), 0, true))->utc();
        } catch (Throwable) {
            // An expression the cron library cannot read: shown, never judged.
        }

        $firstSeen = CarbonImmutable::parse($task['first_seen']);
        $missed = $dueBefore !== null
            && $firstSeen->lessThanOrEqualTo($dueBefore)
            && $runningSince !== null && $runningSince->lessThanOrEqualTo($dueBefore)
            && ($lastAt === null || $lastAt->lessThan($dueBefore));

        return new TaskStatus($name, $task['expression'], $lastAt, $result, $error, $next, $missed ? $dueBefore : null);
    }

    /** @return array<string, array{expression: string, timezone: string, first_seen: string}> */
    private function catalogue(): array
    {
        $value = $this->cache->get(self::CATALOGUE);
        $tasks = [];

        foreach (is_array($value) && is_array($value['tasks'] ?? null) ? $value['tasks'] : [] as $name => $task) {
            if (is_string($name) && is_array($task) && is_string($task['expression'] ?? null) && is_string($task['timezone'] ?? null) && is_string($task['first_seen'] ?? null)) {
                $tasks[$name] = ['expression' => $task['expression'], 'timezone' => $task['timezone'], 'first_seen' => $task['first_seen']];
            }
        }

        return $tasks;
    }

    /** @return array{running_since: CarbonImmutable|null, captured_at: CarbonImmutable|null} */
    private function state(): array
    {
        $value = $this->cache->get(self::CATALOGUE);
        $time = static fn (mixed $at): ?CarbonImmutable => is_string($at) ? CarbonImmutable::parse($at) : null;

        return [
            'running_since' => $time(is_array($value) ? ($value['running_since'] ?? null) : null),
            'captured_at' => $time(is_array($value) ? ($value['captured_at'] ?? null) : null),
        ];
    }
}
