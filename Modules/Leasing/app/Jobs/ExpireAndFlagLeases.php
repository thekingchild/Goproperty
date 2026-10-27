<?php

declare(strict_types=1);

namespace Modules\Leasing\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Leasing\Contracts\CycleCalculator;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Leases\LeaseDates;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;

/**
 * `leases:expire-and-flag` (ARCHITECTURE.md § 7, daily 02:00; P03
 * decision 5): end-date transitions and renewal nudges, per organization,
 * through LeaseService.
 *
 * 1. Drafts whose hold on their unit ran out (`reserved_until` before
 *    today) expire, and their unit is released (a stale reservation).
 * 2. Active leases whose end date has passed expire, and their unit is
 *    released unless another lease holds it. A lease marked `auto_renew`
 *    is left active and flagged instead: rolling it over is a new lease
 *    someone agrees to (no automatic renewal is built yet).
 * 3. Active leases ending within the organization's notice window
 *    (`leasing.ending_soon_days`, default 60) with no renewal are flagged
 *    once (LeaseEndingSoon).
 *
 * "Today" is the date in Lagos. Reads only the `organization_id` of
 * organizations with drafts or active leases, then works inside each
 * one's context (no scope removed). Safe to run again: each lease is
 * locked and re-checked, and a lease already moved is skipped. Stops
 * after BUDGET_SECONDS and queues itself again for the rest, so a shared
 * host's worker never runs it past ~50 s (AGENT.md § 4).
 */
final class ExpireAndFlagLeases implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int BUDGET_SECONDS = 40;

    public int $timeout = 50;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(OrganizationContext $context, LeaseService $leases, CycleCalculator $calculator): void
    {
        $started = microtime(true);
        $organizationIds = DB::table('leases')
            ->whereNull('deleted_at')
            ->whereIn('status', [LeaseStatus::Draft->value, LeaseStatus::Active->value])
            ->distinct()
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach (Organization::query()->whereKey($organizationIds)->orderBy('id')->get() as $organization) {
            $finished = $context->run($organization, fn (): bool => $this->runFor($leases, $calculator, $started));

            if (! $finished) {
                // Out of time: the next run picks up where this one stopped.
                self::dispatch();

                return;
            }
        }
    }

    /** One organization's leases; false when the time budget ran out. */
    private function runFor(LeaseService $leases, CycleCalculator $calculator, float $started): bool
    {
        $today = LeaseDates::today()->toDateString();
        $flagUntil = $calculator->daysAfter(LeaseDates::today(), $leases->endingSoonDays())->toDateString();

        $passes = [
            // Stale reservations.
            fn () => Lease::query()->where('status', LeaseStatus::Draft)->where('reserved_until', '<', $today),
            // Ended leases (auto-renewing ones are flagged below instead).
            fn () => Lease::query()->where('status', LeaseStatus::Active)->where('end_date', '<', $today)->where('auto_renew', false),
        ];

        foreach ($passes as $pass) {
            foreach ($pass()->orderBy('id')->pluck('id') as $id) {
                if (microtime(true) - $started > self::BUDGET_SECONDS) {
                    return false;
                }

                $lease = Lease::query()->find($id);

                try {
                    $lease instanceof Lease && $leases->expire($lease);
                } catch (ValidationException) {
                    // Moved meanwhile (activated, ended, renewed): nothing to do.
                }
            }
        }

        $ending = Lease::query()->where('status', LeaseStatus::Active)->whereNull('ending_soon_flagged_at')->where('end_date', '<=', $flagUntil)->orderBy('id')->pluck('id');

        foreach ($ending as $id) {
            if (microtime(true) - $started > self::BUDGET_SECONDS) {
                return false;
            }

            $lease = Lease::query()->find($id);
            $lease instanceof Lease && $leases->flagEndingSoon($lease);
        }

        return true;
    }
}
