<?php

declare(strict_types=1);

namespace Modules\Tickets\Handover;

use App\Media\Media;
use App\Tenancy\Moves\BuildingMove;
use App\Tenancy\Moves\BuildingMoveFailed;
use App\Tenancy\Moves\MovesBuildingRecords;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Modules\Leasing\Contracts\LeaseSummary;
use Modules\Leasing\Contracts\TenantLeases;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Messages\TicketMessages;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Tickets\TicketRefused;
use Modules\Tickets\Tickets\TicketService;

/**
 * The Tickets module's part of a building move (P04-05; BILLING_PAYMENTS.md
 * § 7.4; docs/plans/P04-subtasks.md "Settled by the product owner",
 * replacing P04-01's "tickets stay with their organization"): the open
 * tickets of the moved buildings go with them, on a management handover's
 * acceptance, at its end and on a one-way return alike, inside the same
 * transaction (any failure rolls the whole move back).
 *
 * "Open" here is every ticket that is not closed: submitted, under review,
 * in progress, resolved, reopened, and a tenant's unsent draft. Closed
 * tickets stay behind as history.
 *
 * Nothing changes organization: for each ticket the receiving organization
 * gets its own (TicketService::receiveMoved(): a new number there, "Moved
 * from {organization}, was TKT-000012", the moved tenant's record and the
 * lease there, resolved stays resolved, a draft stays a draft, anything
 * else `submitted`, nobody assigned), with the public messages and their
 * files copied (TicketMessages::copyShared(); internal notes never cross),
 * and the ticket left behind is closed for good with "Building moved to
 * {organization} on {date}" (TicketService::closeMovedOut(); a draft is
 * discarded). Its history, thread, notices and files stay where they are.
 *
 * The tenant and lease there: the Tenants module's part of the move maps
 * the tenants of the moved live leases (BuildingMove::tenantIn()), and the
 * Leasing module's moves the live leases themselves (same ids), so a
 * ticket keeps its lease when that lease moved and its tenant holds it
 * there (Leasing's TenantLeases, read there); otherwise the copy has no
 * lease, and no tenant when the tenant did not move.
 *
 * Runs after Leasing (30), the portal links (35) and Billing (40), before
 * the media library (90), which does not touch these files: the copies are
 * new files of new records. Each copied file's folder is removed if the
 * move fails.
 */
final readonly class TicketsMover implements MovesBuildingRecords
{
    public function __construct(
        private OrganizationContext $context,
        private TicketService $tickets,
        private TicketMessages $messages,
        private TenantLeases $leases,
    ) {}

    public function order(): int
    {
        return 50;
    }

    public function move(BuildingMove $move): void
    {
        $open = Ticket::query()
            ->whereIn('property_id', $move->propertyIds)
            ->where('status', '!=', TicketStatus::Closed)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($open->isEmpty()) {
            return;
        }

        $on = CarbonImmutable::now();

        foreach ($open as $ticket) {
            $tenantId = $ticket->tenant_profile_id !== null ? $move->tenantIn($ticket->tenant_profile_id) : null;
            $leaseId = $this->leaseThere($move, $ticket, $tenantId);

            $copy = $this->context->run($move->to, fn (): Ticket => $this->tickets->receiveMoved($ticket, $move->from->name, $tenantId, $leaseId));

            if ($ticket->status !== TicketStatus::Draft) {
                $copied = $this->messages->copyShared($ticket, $move->from, $copy, $move->to, function (Media $file) use ($move): void {
                    $this->removeOnFailure($move, $file);
                });
                $move->count('ticket_messages_copied', $copied['messages']);
                $move->count('ticket_files_copied', $copied['files']);
            }

            try {
                $this->context->run($move->from, fn (): Ticket => $this->tickets->closeMovedOut($ticket, $move->to->name, $on, $copy->id));
            } catch (TicketRefused $refused) {
                throw new BuildingMoveFailed($refused->getMessage(), previous: $refused);
            }

            $move->count($ticket->status === TicketStatus::Draft ? 'ticket_drafts_moved' : 'tickets_moved');
        }
    }

    /** The ticket's lease where the buildings go: the same lease, when it moved and the moved tenant holds it there. */
    private function leaseThere(BuildingMove $move, Ticket $ticket, ?int $tenantId): ?int
    {
        if ($tenantId === null || $ticket->lease_id === null) {
            return null;
        }

        $leases = $this->context->run($move->to, fn (): array => $this->leases->forTenant($tenantId));

        return array_any($leases, static fn (LeaseSummary $lease): bool => $lease->id === $ticket->lease_id) ? $ticket->lease_id : null;
    }

    private function removeOnFailure(BuildingMove $move, Media $file): void
    {
        foreach (array_unique(array_filter([$file->disk, $file->conversions_disk])) as $disk) {
            $folder = $move->to->id.'/'.$file->uuid;
            $move->onFailure(static fn () => Storage::disk($disk)->deleteDirectory($folder));
        }
    }
}
