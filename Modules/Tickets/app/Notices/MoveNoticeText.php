<?php

declare(strict_types=1);

namespace Modules\Tickets\Notices;

use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Events\TicketMovedIn;

/**
 * What a ticket that arrived with its building says (Events\TicketMovedIn;
 * P04-05, docs/plans/P04-subtasks.md "Settled by the product owner" 5): to
 * the tenant, that their repair request is now handled by the receiving
 * agency (and, when it was resolved there, that they can still confirm it
 * or reopen it); to the receiving organization's owners and
 * administrators, that it came with the building, under which number,
 * and that nobody is assigned yet.
 */
final readonly class MoveNoticeText implements TicketNoticeText
{
    public function __construct(
        public string $fromOrganization,
        public ?string $fromNumber,
    ) {}

    public static function of(TicketMovedIn $event): self
    {
        return new self($event->fromOrganization, $event->fromNumber);
    }

    public function event(): string
    {
        return 'ticket.moved_in';
    }

    public function subject(TicketFacts $facts, TicketRecipient $to): string
    {
        $values = ['number' => $facts->number, 'title' => $facts->title, 'agency' => $facts->agency, 'from' => $this->fromOrganization];

        return $to->isTenant()
            ? __('Your repair request is now handled by :agency', $values)
            : __(':number moved here from :from: :title', $values);
    }

    public function lines(TicketFacts $facts, TicketRecipient $to): array
    {
        $values = [
            'number' => $facts->number,
            'title' => $facts->title,
            'home' => $facts->home(),
            'agency' => $facts->agency,
            'from' => $this->fromOrganization,
            'old' => $this->fromNumber ?? '—',
            'status' => __($facts->status),
            'days' => $facts->reopenDays,
        ];
        $resolved = $facts->status === TicketStatus::Resolved->label();

        if ($to->isTenant()) {
            $lines = [
                __(':agency now manages :home, so your repair request “:title” is handled by :agency from now on.', $values),
                __('It was :old with :from; its number is now :number. You can follow it here.', $values),
            ];

            if ($resolved) {
                $lines[] = trans_choice('It was marked fixed. If it is, please confirm it. If it is not, you can reopen it within :days day of when it was fixed.|It was marked fixed. If it is, please confirm it. If it is not, you can reopen it within :days days of when it was fixed.', $facts->reopenDays, $values);
            }

            return array_map(static fn (mixed $line): string => (string) $line, $lines);
        }

        return array_map(static fn (mixed $line): string => (string) $line, [
            __('“:title” (:home) came with the building from :from, where it was :old.', $values),
            $resolved
                ? __('It was resolved there and waits for the tenant to confirm it. Nobody here is assigned yet.', $values)
                : __('It is :status here, for you to review. Nobody is assigned yet.', $values),
        ]);
    }
}
