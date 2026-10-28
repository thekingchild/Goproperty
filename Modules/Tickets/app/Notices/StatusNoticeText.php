<?php

declare(strict_types=1);

namespace Modules\Tickets\Notices;

use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Events\TicketStatusChanged;

/**
 * What a ticket's move says (Events\TicketStatusChanged): to the tenant in
 * the agency's name ("Adeyemi Realty has started work on…"), to staff with
 * who did it ("Kemi Adebayo reopened…"). The move's note (a resolution
 * note, a reopening reason, why it closed by itself) is passed on: the
 * tenant sees it on the ticket's page already (P04-01).
 */
final readonly class StatusNoticeText implements TicketNoticeText
{
    public function __construct(
        public ?string $from,
        public string $to,
        public string $actor,
        public ?string $note,
    ) {}

    public static function of(TicketStatusChanged $event): self
    {
        return new self($event->from, $event->to, $event->actor, $event->note);
    }

    public function event(): string
    {
        // A new ticket for staff, whether staff raised it or the tenant sent it.
        return $this->to === TicketStatus::Submitted->value ? 'ticket.raised' : 'ticket.'.$this->to;
    }

    public function subject(TicketFacts $facts, TicketRecipient $to): string
    {
        $values = ['number' => $facts->number, 'title' => $facts->title, 'agency' => $facts->agency, 'tenant' => $facts->tenantName ?? __('The tenant')];

        if ($to->isTenant()) {
            return match (TicketStatus::from($this->to)) {
                TicketStatus::Submitted => __(':agency reported a repair for you: :number', $values),
                TicketStatus::UnderReview => __(':number: :agency is looking into it', $values),
                TicketStatus::InProgress => __(':number: work has started', $values),
                TicketStatus::Resolved => __(':number is fixed: please check', $values),
                TicketStatus::Closed => __(':number is closed', $values),
                TicketStatus::Reopened => __(':number was reopened', $values),
                TicketStatus::Draft => __(':number was updated', $values),
            };
        }

        return match (TicketStatus::from($this->to)) {
            TicketStatus::Submitted => __('New ticket :number: :title', $values),
            TicketStatus::UnderReview => __(':number is under review: :title', $values),
            TicketStatus::InProgress => __(':number is in progress: :title', $values),
            TicketStatus::Resolved => __(':number is resolved: :title', $values),
            TicketStatus::Closed => match ($this->actor) {
                TicketStatusChanged::ACTOR_TENANT => __(':number is closed: :tenant confirmed it is fixed', $values),
                TicketStatusChanged::ACTOR_SYSTEM => __(':number closed automatically: :title', $values),
                default => __(':number is closed: :title', $values),
            },
            TicketStatus::Reopened => $this->actor === TicketStatusChanged::ACTOR_TENANT
                ? __(':number was reopened by :tenant', $values)
                : __(':number was reopened: :title', $values),
            TicketStatus::Draft => __(':number was updated', $values),
        };
    }

    public function lines(TicketFacts $facts, TicketRecipient $to): array
    {
        $values = [
            'number' => $facts->number,
            'title' => $facts->title,
            'home' => $facts->home(),
            'agency' => $facts->agency,
            'tenant' => $facts->tenantName ?? __('The tenant'),
            'actor' => $facts->actorName ?? $facts->agency,
            'priority' => __($facts->priority),
            'days' => $facts->reopenDays,
        ];

        $lines = $to->isTenant() ? $this->tenantLines($values) : $this->staffLines($facts, $values);

        if ($this->note !== null && trim($this->note) !== '') {
            $lines[] = __('Note: :note', ['note' => trim($this->note)]);
        }

        if ($to->isTenant() && $this->to === TicketStatus::Resolved->value) {
            $lines[] = trans_choice('If it is fixed, please confirm it. If it is not, you can reopen it within :days day.|If it is fixed, please confirm it. If it is not, you can reopen it within :days days.', $facts->reopenDays, $values);
        }

        return array_map(static fn (mixed $line): string => (string) $line, $lines);
    }

    /**
     * @param  array<string, string|int>  $values
     * @return list<string>
     */
    private function tenantLines(array $values): array
    {
        return [match (TicketStatus::from($this->to)) {
            TicketStatus::Submitted => __(':agency raised ticket :number for :home: “:title”. You can follow it here.', $values),
            TicketStatus::UnderReview => __(':agency is looking into your repair “:title” (:home).', $values),
            TicketStatus::InProgress => __(':agency has started work on your repair “:title” (:home).', $values),
            TicketStatus::Resolved => __(':agency says your repair “:title” (:home) is fixed.', $values),
            TicketStatus::Closed => $this->actor === TicketStatusChanged::ACTOR_SYSTEM
                ? __('Your repair “:title” (:home) was closed automatically.', $values)
                : __(':agency closed your repair “:title” (:home).', $values),
            TicketStatus::Reopened => __(':agency reopened your repair “:title” (:home).', $values),
            TicketStatus::Draft => __('Your repair “:title” (:home) was updated.', $values),
        }];
    }

    /**
     * @param  array<string, string|int>  $values
     * @return list<string>
     */
    private function staffLines(TicketFacts $facts, array $values): array
    {
        $line = match (TicketStatus::from($this->to)) {
            TicketStatus::Submitted => $this->actor === TicketStatusChanged::ACTOR_TENANT
                ? __(':tenant reported “:title” for :home from the tenant portal.', $values)
                : ($facts->tenantName !== null
                    ? __(':actor raised “:title” for :home, for :tenant.', $values)
                    : __(':actor raised “:title” for :home.', $values)),
            TicketStatus::UnderReview => __(':actor started reviewing “:title” (:home).', $values),
            TicketStatus::InProgress => __(':actor started work on “:title” (:home).', $values),
            TicketStatus::Resolved => __(':actor resolved “:title” (:home).', $values),
            TicketStatus::Closed => match ($this->actor) {
                TicketStatusChanged::ACTOR_TENANT => __(':tenant confirmed “:title” (:home) is fixed, which closed it.', $values),
                TicketStatusChanged::ACTOR_SYSTEM => __('“:title” (:home) was resolved and the tenant did not answer, so it closed automatically.', $values),
                default => __(':actor closed “:title” (:home).', $values),
            },
            TicketStatus::Reopened => $this->actor === TicketStatusChanged::ACTOR_TENANT
                ? __(':tenant says “:title” (:home) is not fixed and reopened it.', $values)
                : __(':actor reopened “:title” (:home).', $values),
            TicketStatus::Draft => __('“:title” (:home) was updated.', $values),
        };

        return $this->to === TicketStatus::Submitted->value ? [$line, __('Priority: :priority.', $values)] : [$line];
    }
}
