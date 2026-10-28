<?php

declare(strict_types=1);

namespace Modules\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket was assigned to a member, given to another, or left unassigned
 * (`assignedToUserId` null), by Tickets\TicketService; after the commit.
 * `actorUserId` is null when the system did it (the member was removed
 * from the organization). Plain ids only.
 */
final readonly class TicketAssignmentChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $organizationId,
        public int $ticketId,
        public ?string $number,
        public ?int $previousUserId,
        public ?int $assignedToUserId,
        public ?int $actorUserId,
    ) {}
}
