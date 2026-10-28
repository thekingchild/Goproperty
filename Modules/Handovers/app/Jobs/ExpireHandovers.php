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
 * Records handover offers nobody answered within 14 days as expired
 * (BILLING_PAYMENTS.md § 7.2), telling both sides. Scheduled hourly
 * (Schedule::job on the `billing` queue, ARCHITECTURE.md § 7). Reads only
 * the `organization_id` of the due rows, then expires them organization by
 * organization inside each one's context (no scope is removed). Safe to
 * run again: an expired offer is no longer pending.
 */
final class ExpireHandovers implements ShouldQueue
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
            ->where('status', HandoverStatus::Pending->value)
            ->where('expires_at', '<=', now())
            ->distinct()
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach (Organization::query()->whereKey($organizationIds)->orderBy('id')->get() as $organization) {
            $context->run($organization, static fn (): int => $handovers->expireDue());
        }
    }
}
