<?php

declare(strict_types=1);

namespace Modules\Tickets\Notices;

/**
 * What one kind of ticket announcement says, to each side (TicketNotifier
 * sends it): a status move (StatusNoticeText), an assignment
 * (AssignmentNoticeText), a message of the thread (MessageNoticeText), a
 * ticket that moved in (MoveNoticeText), a share with the building's owner
 * (SharedNoticeText, P04-06). The tenant reads it in the portal's words
 * (the agency by name), the owner in the agency's name about their
 * building, staff in the workspace's (who did what).
 */
interface TicketNoticeText
{
    /** The event's name, kept in `ticket_notices.event` and the in-app notification, e.g. `ticket.resolved`. */
    public function event(): string;

    public function subject(TicketFacts $facts, TicketRecipient $to): string;

    /** @return list<string> */
    public function lines(TicketFacts $facts, TicketRecipient $to): array;
}
