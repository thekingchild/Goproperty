<?php

declare(strict_types=1);

namespace Modules\LateFees\Jobs;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\LateFees\Charges\LateFeeCharger;

/**
 * `late-fees-charge` (daily 01:20, after `bills-flag-overdue` at 01:00;
 * PL-02, docs/plans/late-fees-2026-11-11.md default 3): every
 * organization that is not cancelled (as rent billing), in its own
 * context, through Charges\LateFeeCharger, which does nothing where late
 * fees are off. Safe to run again (one fee per rent bill, ever). Stops
 * after BUDGET_SECONDS and queues itself again from that organization, so
 * a shared host's worker never runs it past ~50 s (AGENT.md § 4).
 */
final class ChargeLateFees implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int BUDGET_SECONDS = 40;

    public int $timeout = 50;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $fromOrganizationId = 0,
        public readonly int $budgetSeconds = self::BUDGET_SECONDS,
    ) {
        $this->onQueue('billing');
    }

    public function handle(OrganizationContext $context, LateFeeCharger $charger): void
    {
        $started = microtime(true);
        $organizations = Organization::query()
            ->where('id', '>=', $this->fromOrganizationId)
            ->where('status', '!=', OrganizationStatus::Cancelled)
            ->orderBy('id')
            ->get();

        foreach ($organizations as $organization) {
            $finished = $context->run($organization, fn (): ?int => $charger->charge(fn (): bool => microtime(true) - $started > $this->budgetSeconds));

            if ($finished === null) {
                // Out of time: the next run starts again at this organization.
                self::dispatch($organization->id, $this->budgetSeconds);

                return;
            }
        }
    }
}
