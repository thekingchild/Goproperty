<?php

declare(strict_types=1);

namespace Modules\Tickets\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Tickets\TicketService;
use Modules\Tickets\Tickets\TicketSettings;

/**
 * `tickets-auto-close` (ARCHITECTURE.md § 7, daily 04:30;
 * docs/plans/P04-subtasks.md default 2): a resolved ticket the tenant has
 * neither confirmed nor reopened closes itself once the organization's
 * `tickets.auto_close_days` (default 7) have passed since its resolution,
 * through Tickets\TicketService::autoClose() (history row, audit entry and
 * TicketStatusChanged, no causer).
 *
 * Reads only the `organization_id` of organizations with resolved tickets,
 * then works inside each one's context (no scope removed). Safe to run
 * again: each ticket is locked and re-checked, and one already closed or
 * reopened is skipped, so a ticket closes once. Stops after BUDGET_SECONDS
 * and queues itself again for the rest (shared hosting, AGENT.md § 4).
 */
final class CloseResolvedTickets implements ShouldQueue
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

    public function handle(OrganizationContext $context, TicketService $tickets, TicketSettings $settings): void
    {
        $started = microtime(true);
        $organizationIds = DB::table('tickets')
            ->whereNull('deleted_at')
            ->where('status', TicketStatus::Resolved->value)
            ->distinct()
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach (Organization::query()->whereKey($organizationIds)->orderBy('id')->get() as $organization) {
            $finished = $context->run($organization, function () use ($tickets, $settings, $started): bool {
                $cutoff = CarbonImmutable::now()->subDays($settings->autoCloseDays());

                foreach (Ticket::query()->where('status', TicketStatus::Resolved)->where('resolved_at', '<=', $cutoff)->orderBy('id')->pluck('id') as $id) {
                    if (microtime(true) - $started > self::BUDGET_SECONDS) {
                        return false;
                    }

                    $tickets->autoClose((int) $id);
                }

                return true;
            });

            if (! $finished) {
                // Out of time: the next run picks up where this one stopped.
                self::dispatch();

                return;
            }
        }
    }
}
