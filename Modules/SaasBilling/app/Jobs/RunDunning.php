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
use Modules\SaasBilling\Dunning\DunningLadder;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Throwable;

/**
 * `saas-dunning` (P06-05; hourly at :55 on `billing`; BILLING_PAYMENTS.md
 * § 6): every organization with a subscription invoice open past its due
 * date, or whose subscription is past due, in grace or suspended, goes
 * through Dunning\DunningLadder::run(): one step of the ladder and the
 * notice due today. Hourly, so a missed run is made up within the hour;
 * the ladder counts each step from the one before, so nobody loses a day
 * of grace to an outage.
 *
 * Safe to run again and at the same time (AGENT.md § 3 rule 6): queued at
 * most once, one run at a time (a cache lock), and each move and notice
 * happens once (the subscription's state machine and the notice's unique
 * key, under the organization's lock). Stops after BUDGET_SECONDS and
 * queues itself again (AGENT.md § 4). One organization's failure is
 * reported and does not stop the others.
 */
final class RunDunning implements ShouldBeUniqueUntilProcessing, ShouldQueue
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

    public function handle(OrganizationContext $context, DunningLadder $ladder): void
    {
        $lock = Cache::lock('saas:dunning', $this->timeout + 30);

        if (! $lock->get()) {
            return;
        }

        $started = microtime(true);
        $now = CarbonImmutable::now();
        $today = $now->setTimezone('Africa/Lagos')->toDateString();
        $more = false;

        try {
            // Read through the organizations table (no cross-organization subscription or invoice query).
            $due = Organization::query()->whereExists(static fn (Builder $query) => $query->selectRaw('1')->from('subscriptions')
                ->whereColumn('subscriptions.organization_id', 'organizations.id')
                ->where(static fn (Builder $either) => $either
                    ->whereIn('subscriptions.status', [SubscriptionStatus::PastDue->value, SubscriptionStatus::Grace->value, SubscriptionStatus::Suspended->value])
                    ->orWhere(static fn (Builder $active) => $active->where('subscriptions.status', SubscriptionStatus::Active->value)
                        ->whereExists(static fn (Builder $invoices) => $invoices->selectRaw('1')->from('platform_invoices')
                            ->whereColumn('platform_invoices.organization_id', 'organizations.id')
                            ->where('platform_invoices.kind', InvoiceKind::Subscription->value)
                            ->where('platform_invoices.status', InvoiceStatus::Open->value)
                            ->where('platform_invoices.due_date', '<', $today)))));

            foreach ($due->lazyById(100) as $organization) {
                if (microtime(true) - $started > self::BUDGET_SECONDS) {
                    $more = true;

                    break;
                }

                try {
                    $context->run($organization, static fn () => $ladder->run($now));
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
        return 'saas-dunning';
    }
}
