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

    /** @return list<TicketRow> */
    public function closed(): array
    {
        return array_values(array_filter($this->tickets, static fn (TicketRow $row): bool => $row->ticket->status === TicketStatus::Closed));
    }
}
