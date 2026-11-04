<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts;

use App\Platform\Operations\Alerts\Notices\AlertNotice;
use App\Platform\Operations\Alerts\Notices\AlertNotifier;
use App\Platform\Operations\Alerts\Notices\NoticeKind;
use App\Platform\Operations\OperationsAudit;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Turns the checks into incidents and notices (PLATFORM_CONSOLE.md § 2
 * "Alerts"; docs/plans/P08-subtasks.md defaults 29–49):
 *
 * - a subject newly failing opens an incident (`operations_alerts`) and is
 *   announced at once (raised);
 * - while it stays failing, a reminder every `operations.alert_renotify_minutes`
 *   (default 60), never more often, and never for a reminder rule (the drill);
 * - once a check no longer finds it, the incident is resolved and a
 *   "cleared" notice says so.
 *
 * Raised and cleared incidents are audited (log `operations`). One run at a
 * time (a cache lock): the scheduler's minute check, the web watchdog and
 * the uptime link may all call it. A check that throws is logged and its
 * open incidents are left as they are, never cleared by mistake.
 */
final readonly class AlertMonitor
{
    private const string LOCK = 'operations:alerts';

    /** @param  iterable<AlertCheck>  $checks */
    public function __construct(
        private iterable $checks,
        private AlertNotifier $notifier,
        private OperationsAudit $audit,
        private Cache $cache,
    ) {}

    /**
     * @param  'scheduler'|'web'|'uptime'  $source  what started the run (kept on a raised incident)
     * @param  list<AlertRule>|null  $only  the rules to check; null: all
     * @return list<string>|null the notices sent ("raised|scheduler_stopped|"), null when alerts are off or another run holds the lock
     */
    public function run(string $source, ?array $only = null, ?CarbonImmutable $now = null): ?array
    {
        $settings = AlertSettings::current();

        if (! $settings->enabled) {
            return null;
        }

        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('Operations alerts need a cache store that supports locks.');
        }

        $lock = $store->lock(self::LOCK, 120);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->evaluate($source, $only, $now ?? CarbonImmutable::now(), $settings);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<AlertRule>|null  $only
     * @return list<string>
     */
    private function evaluate(string $source, ?array $only, CarbonImmutable $now, AlertSettings $settings): array
    {
        $failing = [];
        $checked = [];
        $checks = [];

        foreach ($this->checks as $check) {
            if ($only !== null && ! in_array($check->rule(), $only, true)) {
                continue;
            }

            try {
                foreach ($check->failing($now, $settings) as $condition) {
                    $failing[$condition->key()] = $condition;
                }

                $checked[] = $check->rule()->value;
                $checks[$check->rule()->value] = $check;
            } catch (Throwable $exception) {
                Log::error('An operations alert check failed and was skipped: '.$check->rule()->value.' ('.class_basename($exception).').');
            }
        }

        $open = OperationsAlert::query()->open()->whereIn('rule', $checked)->get()->keyBy('open_key');
        $sent = [];

        foreach ($failing as $key => $condition) {
            $incident = $open->get($key);

            if ($incident === null) {
                $incident = $this->open($condition, $source, $now);
                $this->notify(NoticeKind::Raised, $incident, $condition->summary, $now, $settings);
                $sent[] = 'raised|'.$key;

                continue;
            }

            $incident->fill(['summary' => mb_substr($condition->summary, 0, 500), 'details' => $condition->details]);

            if ($condition->rule->repeats() && $incident->last_notified_at !== null && $incident->last_notified_at->lessThanOrEqualTo($now->subMinutes($settings->renotifyMinutes))) {
                $this->notify(NoticeKind::Reminder, $incident, $condition->summary, $now, $settings);
                $sent[] = 'reminder|'.$key;
            } elseif ($incident->isDirty()) {
                $incident->save();
            }
        }

        foreach ($open as $key => $incident) {
            $check = $checks[$incident->rule->value] ?? null;

            if (isset($failing[$key]) || $check === null) {
                continue;
            }

            $text = $check->cleared($incident, $now);
            $incident->forceFill(['open_key' => null, 'resolved_at' => $now])->save();
            $this->audit->record('alert.cleared', "Alert cleared: {$incident->rule->label()}", $incident, properties: ['rule' => $incident->rule->value, 'subject' => $incident->subject]);
            Log::info('Operations alert cleared: '.$incident->rule->value.($incident->subject !== '' ? ' '.$incident->subject : ''));
            $this->notify(NoticeKind::Cleared, $incident, $text, $now, $settings, count: false);
            $sent[] = 'cleared|'.$key;
        }

        return $sent;
    }

    private function open(AlertCondition $condition, string $source, CarbonImmutable $now): OperationsAlert
    {
        $incident = OperationsAlert::query()->create([
            'rule' => $condition->rule,
            'subject' => $condition->subject,
            'open_key' => $condition->key(),
            'summary' => mb_substr($condition->summary, 0, 500),
            'details' => $condition->details,
            'raised_by' => $source,
            'started_at' => $now,
            'notifications' => 0,
        ]);

        $this->audit->record('alert.raised', "Alert raised: {$condition->rule->label()}", $incident, properties: ['rule' => $condition->rule->value, 'subject' => $condition->subject, 'raised_by' => $source]);
        Log::warning('Operations alert raised: '.$condition->rule->value.($condition->subject !== '' ? ' '.$condition->subject : ''));

        return $incident;
    }

    private function notify(NoticeKind $kind, OperationsAlert $incident, string $text, CarbonImmutable $now, AlertSettings $settings, bool $count = true): void
    {
        $this->notifier->send(new AlertNotice(
            kind: $kind,
            rule: $incident->rule,
            text: $text,
            startedAt: $incident->started_at !== null ? CarbonImmutable::instance($incident->started_at) : null,
            at: $now,
            dashboardUrl: AlertNotifier::dashboardUrl(),
        ), $settings);

        if ($count) {
            $incident->forceFill(['last_notified_at' => $now, 'notifications' => $incident->notifications + 1])->save();
        }
    }
}
