<?php

declare(strict_types=1);

namespace Modules\Tickets\Notices;

/**
 * What the building's owner is told when a member shares a ticket with them
 * (Events\TicketSharedWithOwner; P04-06,
 * docs/plans/owner-visibility-2026-10-29.md, P04-06 default 3): in the
 * agency's name, which repair, where, its status and priority, and that
 * they can follow it in the owner portal. The owner only.
 */
final readonly class SharedNoticeText implements TicketNoticeText
{
    public function event(): string
    {
        return 'ticket.shared';
    }

    public function subject(TicketFacts $facts, TicketRecipient $to): string
    {
        return __(':agency shared a repair with you: :number at :building', ['agency' => $facts->agency, 'number' => $facts->number, 'building' => $facts->building]);
    }

    public function lines(TicketFacts $facts, TicketRecipient $to): array
    {
        $values = ['agency' => $facts->agency, 'number' => $facts->number, 'title' => $facts->title, 'home' => $facts->home(), 'status' => __($facts->status), 'priority' => __($facts->priority)];

        return array_map(static fn (mixed $line): string => (string) $line, [
            __(':agency shared the repair :number, “:title” (:home), with you, so you can follow it.', $values),
            __('Status: :status. Priority: :priority.', $values),
            __('You will be told when its status changes or someone replies.'),
        ]);
    }
}
