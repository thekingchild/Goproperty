<?php

declare(strict_types=1);

namespace Modules\Tickets\Notices;

/**
 * What a new assignee is told (Events\TicketAssignmentChanged): the ticket,
 * where, its status and priority, and who gave it to them. Staff only.
 */
final readonly class AssignmentNoticeText implements TicketNoticeText
{
    public function event(): string
    {
        return 'ticket.assigned';
    }

    public function subject(TicketFacts $facts, TicketRecipient $to): string
    {
        return __(':number is assigned to you: :title', ['number' => $facts->number, 'title' => $facts->title]);
    }

    public function lines(TicketFacts $facts, TicketRecipient $to): array
    {
        $values = ['number' => $facts->number, 'title' => $facts->title, 'home' => $facts->home(), 'actor' => $facts->actorName, 'status' => __($facts->status), 'priority' => __($facts->priority)];

        return [
            $facts->actorName !== null
                ? __(':actor assigned you ticket :number, “:title”, for :home.', $values)
                : __('Ticket :number, “:title”, for :home, is now assigned to you.', $values),
            __('Status: :status. Priority: :priority.', $values),
        ];
    }
}
