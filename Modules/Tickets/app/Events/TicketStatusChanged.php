<?php

declare(strict_types=1);

namespace Modules\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * A ticket moved in its state machine (ARCHITECTURE.md § 6.6): sent by
 * Tickets\TicketService once for every row it writes to
 * `ticket_status_history` — a staff ticket's creation (`from` null, `to`
 * `submitted`) and a tenant's sending included — except a tenant's
 * unsent draft (nobody else sees it). Dispatched after the move's
 * transaction commits (ShouldDispatchAfterCommit), so a listener never
 * acts on a move that rolled back. The notifications on every transition
 * (P04-03) listen here.
 *
 * Plain values only, never the Tickets models: `from` and `to` are
 * `tickets.status` values; `actor` says who moved it — `tenant` (from the
 * portal), `staff` (a member) or `system` (tickets-auto-close, actorUserId
 * null) — so a listener need not tell the person who acted.
 *
 * `key` names this one move (a ULID, made when the event is): the
 * notifications (P04-03, Notices\TicketNotifier) tell each party once per
 * key, so a retried or replayed listener tells nobody twice.
 */
final readonly class TicketStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const string ACTOR_TENANT = 'tenant';

    public const string ACTOR_STAFF = 'staff';

    public const string ACTOR_SYSTEM = 'system';

    public string $key;

    public function __construct(
        public int $organizationId,
        public int $ticketId,
        public ?string $number,
        public ?string $from,
        public string $to,
        public string $actor,
        public ?int $actorUserId,
        public int $propertyId,
        public int $unitId,
        public ?int $tenantProfileId,
        public ?int $assignedToUserId,
        public ?string $note,
        ?string $key = null,
    ) {
        $this->key = $key ?? (string) Str::ulid();
    }
}
