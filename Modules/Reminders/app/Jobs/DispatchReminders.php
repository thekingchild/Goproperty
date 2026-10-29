<?php

declare(strict_types=1);

namespace Modules\Reminders\Jobs;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Modules\Reminders\Reminders\ReminderClock;
use Modules\Reminders\Reminders\ReminderPlanner;
use Modules\Reminders\Reminders\ReminderSender;
use Throwable;

/**
 * `reminders:dispatch` (ARCHITECTURE.md § 6.7, § 7: hourly at :35 on the
 * `notifications` queue; P05-01): organization by organization, inside its
 * own context, plans today's reminders (Reminders\ReminderPlanner) and
 * sends the pending ones whose send time (08:00 Lagos) has come
 * (Reminders\ReminderSender). Every organization except cancelled ones
 * (suspended ones too: their tenants' bills still stand; the planner leaves
 * out renewal alerts there). A failure in one organization is reported and
 * does not stop the others; the next hour's run picks up what it left.
 *
 * Safe to run again and at the same time (AGENT.md § 3 rule 6): queued at
 * most once (ShouldBeUniqueUntilProcessing), run one at a time (a cache
 * lock), each reminder planned once (its dedupe key, unique per
 * organization) and sent once (its row locked, its status). Stops after
 * BUDGET_SECONDS and queues itself again from the organization it was on,
 * so a shared host's worker never runs it past ~50 s (AGENT.md § 4).
 */
final class DispatchReminders implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int BUDGET_SECONDS = 40;

    public const string LOCK = 'reminders:dispatch';

    public int $timeout = 50;

    public int $tries = 3;

    public int $backoff = 60;

    /** How long a queued copy keeps others from being queued. */
    public int $uniqueFor = 3000;

    public function __construct(public readonly int $fromOrganizationId = 0)
    {
        $this->onQueue('notifications');
    }

    public function handle(OrganizationContext $context, ReminderPlanner $planner, ReminderSender $sender): void
    {
        // Longer than a run can last ($timeout), so it never lapses mid-run.
        $lock = Cache::lock(self::LOCK, $this->timeout + 30);

        if (! $lock->get()) {
            return;
        }

        $started = microtime(true);
        $stop = static fn (): bool => microtime(true) - $started > self::BUDGET_SECONDS;
        $today = ReminderClock::today();
        $now = CarbonImmutable::now();
        $resumeFrom = null;

        try {
            $organizations = Organization::query()
                ->where('id', '>=', $this->fromOrganizationId)
                ->where('status', '!=', OrganizationStatus::Cancelled);

            foreach ($organizations->lazyById(100) as $organization) {
                $finished = self::guarded(static fn (): bool => $context->run($organization, static fn (): bool => $planner->plan($today, $stop)
                    && $sender->send($now, $today, $stop)));

                if (! $finished) {
                    $resumeFrom = $organization->id;

                    break;
                }
            }
        } finally {
            $lock->release();
        }

        if ($resumeFrom !== null) {
            self::dispatch($resumeFrom);
        }
    }

    /**
     * One organization's run; a failure is reported and counts as done, so
     * it never stops the next organizations' reminders.
     *
     * @param  callable(): bool  $run
     */
    private static function guarded(callable $run): bool
    {
        try {
            return $run();
        } catch (Throwable $failure) {
            report($failure);

            return true;
        }
    }

    public function uniqueId(): string
    {
        return 'dispatch-reminders';
    }
}
