<?php

declare(strict_types=1);

namespace Modules\Handovers\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Handovers\Handovers\HandoverService;

/**
 * Ends managements whose notice ran out (BILLING_PAYMENTS.md § 7.6 "At
 * the end date (hourly job)"): each handover's buildings and live records
 * move back to the landlord's organization in one transaction, and both
 * sides are told. Scheduled hourly (Schedule::job on the `billing` queue,
 * ARCHITECTURE.md § 7). Reads only the `organization_id` of the due rows,
 * then ends them organization by organization inside each one's context.
 * Safe to run again: an ended handover is no longer ending; one whose move
 * fails stays ending and is tried again next hour. A handover moves a
 * landlord's buildings (a handful), well inside the job's time.
 */
final class EndHandovers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 50;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct()
    {
        $this->onQueue('billing');
    }

    public function handle(OrganizationContext $context, HandoverService $handovers): void
    {
        $organizationIds = DB::table('management_handovers')
            ->where('status', HandoverStatus::Ending->value)
            ->where('ends_at', '<=', now())
            ->distinct()
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach (Organization::query()->whereKey($organizationIds)->orderBy('id')->get() as $organization) {
            $context->run($organization, static fn (): int => $handovers->endDue());
        }
    }
}
