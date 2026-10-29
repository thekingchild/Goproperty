<?php

declare(strict_types=1);

namespace Modules\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * A member shared a ticket with the owner of its building (P04-06;
 * docs/plans/owner-visibility-2026-10-29.md, P04-06 default 3): sent by
 * Tickets\TicketService::shareWithOwner() after the transaction commits
 * (ShouldDispatchAfterCommit), so nobody is told of a share that rolled
 * back. Listeners\TellTicketParties tells the owner's portal account once
 * ("{agency} shared a repair with you"). Stopping the share sends nothing:
 * unsharing tells nobody.
 *
 * Plain values only. `key` (a ULID) names this one share, so a retried
 * listener tells nobody twice; sharing again after it was stopped is a new
 * share, with its own key.
 */
final readonly class TicketSharedWithOwner implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public string $key;

    public function __construct(
        public int $organizationId,
        public int $ticketId,
        public string $number,
        public int $sharedByUserId,
        ?string $key = null,
    ) {
        $this->key = $key ?? (string) Str::ulid();
    }
}
