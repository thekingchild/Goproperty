<?php

declare(strict_types=1);

namespace App\Subscriptions\Jobs;

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Subscriptions\SubscriptionChangeRefused;
use App\Subscriptions\SubscriptionService;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * `subscriptions-trials` (P06-01b; hourly at :15 on `billing`;
 * docs/plans/P06-subtasks.md default 10): every trial whose end has passed
 * becomes `active` through SubscriptionService::endTrial(), its first
 * billing period starting on the trial's last day (P06-02 invoices it on the
 * SubscriptionStatusChanged event). Hourly, so a missed run is made up
 * within the hour.
 *
 * Safe to run again and at the same time (AGENT.md § 3 rule 6): queued at
 * most once, one run at a time (a cache lock), and a subscription no longer
 * in trial is skipped under its row lock. Stops after BUDGET_SECONDS and
 * queues itself again (AGENT.md § 4). One organization's failure is
 * reported and does not stop the others.
 */
final class EndTrials implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int BUDGET_SECONDS = 40;

    public int $timeout = 50;

    public int $tries = 3;

    public int $backoff = 60;

    public int $uniqueFor = 3000;

    public function __construct()
    {
        $this->onQueue('billing');
    }

    public function handle(OrganizationContext $context, SubscriptionService $subscriptions): void
    {
        $lock = Cache::lock('subscriptions:end-trials', $this->timeout + 30);

        if (! $lock->get()) {
            return;
        }

        $started = microtime(true);
        $now = CarbonImmutable::now();
        $more = false;

        try {
            // Organizations whose trial is over: read through the organizations table (no cross-organization subscription query).
            $due = Organization::query()->whereExists(static fn (Builder $query) => $query->selectRaw('1')->from('subscriptions')
                ->whereColumn('subscriptions.organization_id', 'organizations.id')
                ->where('subscriptions.status', SubscriptionStatus::Trialing->value)
                ->where('subscriptions.trial_ends_at', '<=', $now));

            foreach ($due->lazyById(100) as $organization) {
                if (microtime(true) - $started > self::BUDGET_SECONDS) {
                    $more = true;

                    break;
                }

                try {
                    $context->run($organization, static fn () => $subscriptions->endTrial($now));
                } catch (SubscriptionChangeRefused) {
                    // Ended or changed meanwhile.
                } catch (Throwable $failure) {
                    report($failure);
                }
            }
        } finally {
            $lock->release();
        }

        if ($more) {
            self::dispatch();
        }
    }

    public function uniqueId(): string
    {
        return 'subscriptions-end-trials';
    }
}
