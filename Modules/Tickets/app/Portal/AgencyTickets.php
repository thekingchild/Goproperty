<?php

declare(strict_types=1);

namespace Modules\Tickets\Portal;

use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Lists\TicketRow;

/**
 * One agency's tickets in the tenant portal (TenantTickets): the agency's
 * frame now (name, colour, initials; UX_DESIGN.md § 2), the user's tickets
 * there (drafts too), newest first, and whether they may raise one now
 * (not while the agency is suspended, nor in a read-only support session).
 * The portal's inbox (P04-03) groups them: open — drafts, "Fixed? Please
 * check" (resolved), being dealt with — and finished (closed).
 * Plain values, read inside the agency's organization and safe to render
 * outside it.
 */
final readonly class AgencyTickets
{
    /** @param  list<TicketRow>  $tickets */
    public function __construct(
        public int $organizationId,
        public string $name,
        public string $colour,
        public string $initials,
        public bool $readOnly,
        public bool $canRaise,
        public array $tickets,
    ) {}

    /** @return list<TicketRow> */
    public function drafts(): array
    {
        return array_values(array_filter($this->tickets, static fn (TicketRow $row): bool => $row->ticket->status === TicketStatus::Draft));
    }

    /** @return list<TicketRow> sent and not closed */
    public function current(): array
    {
        return array_values(array_filter($this->tickets, static fn (TicketRow $row): bool => ! in_array($row->ticket->status, [TicketStatus::Draft, TicketStatus::Closed], true)));
    }

    /** @return list<TicketRow> resolved: the tenant is asked to confirm or reopen (P04-03 inbox) */
    public function waitingForYou(): array
    {
        return array_values(array_filter($this->tickets, static fn (TicketRow $row): bool => $row->ticket->status === TicketStatus::Resolved));
    }

    /** @return list<TicketRow> sent and waiting for the agency: submitted, under review, in progress, reopened */
    public function beingDealtWith(): array
    {
        return array_values(array_filter($this->tickets, static fn (TicketRow $row): bool => $row->ticket->status->isOpen()));
    }

    /** The inbox's "Open" tab: drafts and every ticket not closed yet. */
    public function openCount(): int
    {
        return count($this->tickets) - count($this->closed());
    }

    /** The inbox's "Finished" tab: closed tickets. */
    public function finishedCount(): int
    {
        return count($this->closed());
    }

    /** @return list<TicketRow> */
    public function closed(): array
    {
        return array_values(array_filter($this->tickets, static fn (TicketRow $row): bool => $row->ticket->status === TicketStatus::Closed));
    }
}
