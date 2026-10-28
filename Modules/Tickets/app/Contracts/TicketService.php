<?php

declare(strict_types=1);

namespace Modules\Tickets\Contracts;

use App\Models\User;

/**
 * What other modules and Core may ask of maintenance tickets
 * (ARCHITECTURE.md § 8 rule 3): sent tickets only (a tenant's unsent draft
 * is nobody else's business), read in the current organization (without
 * one it throws App\Tenancy\MissingOrganizationContext). With $for, only
 * what that member may see (TicketPolicy: an agent narrowed to buildings
 * sees theirs). Every change goes through the Tickets module's own
 * TicketService and pages; events: Modules\Tickets\Events. Bound in
 * TicketsServiceProvider.
 */
interface TicketService
{
    /** The ticket, or null when there is no such sent ticket here or $for may not see it. */
    public function find(int $ticketId, ?User $for = null): ?TicketSummary;

    /** How many tickets wait for the organization (submitted, under review, in progress, reopened): an inbox count. */
    public function openCount(?User $for = null): int;

    /**
     * A lease's tickets, newest first.
     *
     * @return list<TicketSummary>
     */
    public function forLease(int $leaseId, ?User $for = null): array;
}
