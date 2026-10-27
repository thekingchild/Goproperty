<?php

declare(strict_types=1);

namespace Modules\Billing\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Bills\BillDates;
use Modules\Billing\Bills\BillService;
use Modules\Billing\Enums\BillStatus;
use Throwable;

/**
 * `bills:flag-overdue` (ARCHITECTURE.md § 6.4, § 7: daily 01:00, `billing`
 * queue; P03 decision 5): bills still unpaid or partly paid after their
 * due date (Lagos) become `overdue`, which the OVERDUE badge shows
 * (BRANDING_DOCUMENTS.md § 2) and the reminder ladder starts from (P05,
 * BillOverdue). Through BillService::flagOverdue(), per organization in
 * its context: reads only the `organization_id` of organizations with such
 * bills (its own table), never anything across them.
 *
 * Safe to run again: each bill is locked and checked, a flagged one is
 * left alone. Stops after BUDGET_SECONDS and queues itself again.
 */
final class FlagOverdueBills implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int BUDGET_SECONDS = 40;

    public int $timeout = 50;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct()
    {
        $this->onQueue('billing');
    }

    public function handle(OrganizationContext $context, BillService $bills): void
    {
        $started = microtime(true);
        $stop = static fn (): bool => microtime(true) - $started > self::BUDGET_SECONDS;
        $organizationIds = DB::table('bills')
            ->whereNull('deleted_at')
            ->whereIn('status', array_map(static fn (BillStatus $status): string => $status->value, BillStatus::flaggable()))
            ->where('due_date', '<', BillDates::today()->toDateString())
            ->distinct()
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach (Organization::query()->whereKey($organizationIds)->orderBy('id')->get() as $organization) {
            try {
                $flagged = $context->run($organization, static fn (): int|false => $bills->flagOverdue($stop));
            } catch (Throwable $failure) {
                // One organization's failure must not keep the others' bills from being flagged.
                report($failure);
                $flagged = 0;
            }

            if ($flagged === false) {
                // Out of time: the next run picks up where this one stopped.
                self::dispatch();

                return;
            }
        }
    }
}
