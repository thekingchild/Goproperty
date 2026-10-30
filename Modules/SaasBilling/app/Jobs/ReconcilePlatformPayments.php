<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Jobs;

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
use Modules\SaasBilling\Enums\PlatformPaymentStatus;
use Modules\SaasBilling\Models\PlatformPayment;
use Modules\SaasBilling\Payments\PlatformPaymentSettlement;
use Modules\SaasBilling\Paystack\PaystackUnavailable;
use Modules\SaasBilling\Paystack\PlatformPaystack;
use Throwable;

/**
 * `saas-paystack-reconcile` (P06-03; hourly at :50 on `billing`;
 * BILLING_PAYMENTS.md § 4.2 "hourly reconciliation … catches missed
 * webhooks"): every payment through GoProperty's Paystack still `pending`
 * after MIN_AGE_MINUTES is asked about again
 * (PlatformPaymentSettlement::settle, verified by fetch), up to
 * MAX_AGE_HOURS old: an abandoned checkout stays pending after that and is
 * no longer asked about. SMS bundles and subscription invoices alike.
 *
 * Safe to run again and at the same time (AGENT.md § 3 rule 6): queued at
 * most once, one run at a time (a cache lock), and a payment is settled
 * once under its lock. Stops when Paystack cannot be asked (the next hour
 * tries again) or after BUDGET_SECONDS, queueing itself again. One
 * organization's failure is reported and does not stop the others.
 */
final class ReconcilePlatformPayments implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int BUDGET_SECONDS = 40;

    public const int MIN_AGE_MINUTES = 15;

    public const int MAX_AGE_HOURS = 72;

    public int $timeout = 50;

    public int $tries = 1;

    public int $uniqueFor = 3000;

    public function __construct()
    {
        $this->onQueue('billing');
    }

    public function handle(OrganizationContext $context, PlatformPaymentSettlement $settlement, PlatformPaystack $paystack): void
    {
        if (! $paystack->available()) {
            return;
        }

        $lock = Cache::lock('saas:paystack-reconcile', $this->timeout + 30);

        if (! $lock->get()) {
            return;
        }

        $started = microtime(true);
        $now = CarbonImmutable::now();
        [$from, $until] = [$now->subHours(self::MAX_AGE_HOURS), $now->subMinutes(self::MIN_AGE_MINUTES)];
        $more = false;

        try {
            // Organizations with a payment to ask about: read through the organizations table.
            $organizations = Organization::query()->whereExists(static fn (Builder $query) => $query->selectRaw('1')->from('platform_payments')
                ->whereColumn('platform_payments.organization_id', 'organizations.id')
                ->where('platform_payments.status', PlatformPaymentStatus::Pending->value)
                ->whereNotNull('platform_payments.paystack_reference')
                ->whereBetween('platform_payments.created_at', [$from, $until]));

            foreach ($organizations->lazyById(100) as $organization) {
                $references = $context->run($organization, static fn () => PlatformPayment::query()
                    ->where('status', PlatformPaymentStatus::Pending)
                    ->whereNotNull('paystack_reference')
                    ->whereBetween('created_at', [$from, $until])
                    ->orderBy('id')
                    ->pluck('paystack_reference')
                    ->all());

                foreach ($references as $reference) {
                    if (microtime(true) - $started > self::BUDGET_SECONDS) {
                        $more = true;

                        break 2;
                    }

                    try {
                        $context->run($organization, static fn () => $settlement->settle((string) $reference));
                    } catch (PaystackUnavailable) {
                        return;
                    } catch (Throwable $failure) {
                        report($failure);
                    }
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
        return 'saas-paystack-reconcile';
    }
}
