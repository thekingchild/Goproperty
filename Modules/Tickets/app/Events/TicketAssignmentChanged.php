<?php

declare(strict_types=1);

namespace Modules\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * A ticket was assigned to a member, given to another, or left unassigned
 * (`assignedToUserId` null), by Tickets\TicketService; after the commit.
 * `actorUserId` is null when the system did it (the member was removed
 * from the organization). Plain ids only. `key` names this one change (a
 * ULID): the new assignee is told once per key (P04-03).
 */
final readonly class TicketAssignmentChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public string $key;

    public function __construct(
        public int $organizationId,
        public int $ticketId,
        public ?string $number,
        public ?int $previousUserId,
        public ?int $assignedToUserId,
        public ?int $actorUserId,
        ?string $key = null,
    ) {
        $this->key = $key ?? (string) Str::ulid();
    }
}
