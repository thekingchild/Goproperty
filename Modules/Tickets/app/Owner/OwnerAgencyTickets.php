<?php

declare(strict_types=1);

namespace Modules\Tickets\Owner;

use Modules\Tickets\Lists\TicketRow;

/**
 * One agency's tickets for one of the owner's profiles in `/owner`
 * (OwnerTickets): the agency's frame now (name, colour, initials) and the
 * sent tickets of the owner's buildings there that the agency shared with
 * the owner (P04-06), newest first. Plain values,
 * read inside the agency's organization and safe to render outside it.
 */
final readonly class OwnerAgencyTickets
{
    /** @param  list<TicketRow>  $tickets */
    public function __construct(
        public int $organizationId,
        public int $ownerProfileId,
        public string $name,
        public string $colour,
        public string $initials,
        public array $tickets,
    ) {}
}
