<?php

declare(strict_types=1);

namespace Modules\Tickets\Lists;

use Modules\Tickets\Models\Ticket;

/**
 * A ticket with the names shown next to it: the unit's label and its
 * building's, the tenant's and the assigned member's (read a page or an
 * export chunk at a time: TicketParties).
 */
final readonly class TicketRow
{
    public function __construct(
        public Ticket $ticket,
        public string $unitLabel,
        public string $buildingName,
        public ?string $tenantName,
        public ?string $assigneeName,
        public ?string $raisedByName,
    ) {}
}
