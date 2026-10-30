<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Jobs;

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Modules\SaasBilling\Metering\SubscriptionMetering;
use Throwable;

/**
 * `saas-meter-units` (P06-02; hourly at :45 on `billing`; BILLING_PAYMENTS.md
 * § 3; docs/plans/P06-subtasks.md default 16): every `active` or `past_due`
 * subscription whose billing period is over, or which has not been
 * invoiced since its trial ended, is invoiced through
 * SubscriptionMetering::billDue(): monthly ones on the 1st (Lagos), yearly
 * ones on their date. Hourly rather than once on the 1st, so a missed run
 * is made up within the hour and yearly renewals fall on any day.
 *
 * Safe to run again and at the same time (AGENT.md § 3 rule 6): queued at
 * most once, one run at a time (a cache lock), and each period is invoiced
 * once (the invoice's unique key, under the subscription's lock). Stops
 * after BUDGET_SECONDS and queues itself again (AGENT.md § 4). One
 * organization's failure is reported and does not stop the others.
 */
final class MeterUnits implements ShouldBeUniqueUntilProcessing, ShouldQueue
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

    public function handle(OrganizationContext $context, SubscriptionMetering $metering): void
    {
        $lock = Cache::lock('saas:meter-units', $this->timeout + 30);

        if (! $lock->get()) {
            return;
        }

        $started = microtime(true);
        $now = CarbonImmutable::now();
        $today = $now->setTimezone('Africa/Lagos')->toDateString();
        $more = false;

        try {
            // Organizations with a subscription to invoice: read through the organizations table (no cross-organization subscription query).
            $due = Organization::query()->whereExists(static fn (Builder $query) => $query->selectRaw('1')->from('subscriptions')
                ->whereColumn('subscriptions.organization_id', 'organizations.id')
                ->whereIn('subscriptions.status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
                ->where(static fn (Builder $period) => $period->whereNull('subscriptions.current_period_end')
                    ->orWhere(static fn (Builder $over) => $over->where('subscriptions.current_period_end', '<', $today)->where('subscriptions.cancel_at_period_end', false))));

            foreach ($due->lazyById(100) as $organization) {
                if (microtime(true) - $started > self::BUDGET_SECONDS) {
                    $more = true;

                    break;
                }

                try {
                    $context->run($organization, static fn () => $metering->billDue($now));
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
        return 'saas-meter-units';
    }
}
