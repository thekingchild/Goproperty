<?php

declare(strict_types=1);

namespace Modules\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was added to a ticket's thread (P04-02): a reply by the
 * tenant, a member or the building's owner, or a member's internal note
 * (`internal` true: only staff may ever be told about it). Sent by
 * Messages\TicketMessages once per message, after the transaction commits
 * (ShouldDispatchAfterCommit), so a listener never acts on a message that
 * rolled back. Notifications (P04-03) listen here; a listener must not
 * tell the tenant or the owner about an internal note.
 *
 * Plain values only, never the Tickets models: `authorKind` is a
 * `ticket_messages.author_kind` value (`tenant`, `staff`, `owner`);
 * `tenantProfileId` and `assignedToUserId` are the ticket's at the time;
 * `attachmentCount` the files kept with the message.
 */
final readonly class TicketMessagePosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $organizationId,
        public int $ticketId,
        public string $number,
        public int $messageId,
        public string $authorKind,
        public ?int $authorUserId,
        public bool $internal,
        public ?int $tenantProfileId,
        public ?int $assignedToUserId,
        public int $propertyId,
        public int $attachmentCount,
    ) {}
}
