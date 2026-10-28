<?php

declare(strict_types=1);

namespace Modules\Tickets\Notices;

/**
 * What one kind of ticket announcement says, to each side (TicketNotifier
 * sends it): a status move (StatusNoticeText), an assignment
 * (AssignmentNoticeText), a message of the thread (MessageNoticeText).
 * The tenant reads it in the portal's words (the agency by name), staff in
 * the workspace's (who did what).
 */
interface TicketNoticeText
{
    /** The event's name, kept in `ticket_notices.event` and the in-app notification, e.g. `ticket.resolved`. */
    public function event(): string;

    public function subject(TicketFacts $facts, TicketRecipient $to): string;

    /** @return list<string> */
    public function lines(TicketFacts $facts, TicketRecipient $to): array;
}
