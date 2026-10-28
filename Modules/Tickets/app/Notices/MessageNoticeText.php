<?php

declare(strict_types=1);

namespace Modules\Tickets\Notices;

use Modules\Tickets\Enums\MessageAuthor;

/**
 * What a new message of a ticket's thread says (Events\TicketMessagePosted,
 * P04-02's thread): who wrote, the ticket's number and title, a short
 * excerpt of a reply and how many files came with it, and the link. An
 * **internal note is never quoted** (no excerpt, even to staff, who can
 * read it on the page): the email leaves the system, and only staff are
 * ever told about a note (Listeners\TellTicketParties). The tenant reads
 * the agency's name for a member's reply; staff read the author's name.
 */
final readonly class MessageNoticeText implements TicketNoticeText
{
    /** Characters of a reply quoted in the notice. */
    public const int EXCERPT = 140;

    public function __construct(
        public MessageAuthor $author,
        public bool $internal,
        public ?string $excerpt,
        public int $attachmentCount,
    ) {}

    /** The reply's words as quoted: one line, at most EXCERPT characters; none for an internal note or a files-only message. */
    public static function excerptOf(?string $body, bool $internal): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $body));

        if ($internal || $text === '') {
            return null;
        }

        return mb_strlen($text) > self::EXCERPT ? rtrim(mb_substr($text, 0, self::EXCERPT - 1)).'…' : $text;
    }

    public function event(): string
    {
        return $this->internal ? 'ticket.note_added' : 'ticket.replied';
    }

    public function subject(TicketFacts $facts, TicketRecipient $to): string
    {
        $values = ['number' => $facts->number, 'title' => $facts->title, 'author' => $this->authorName($facts, $to)];

        if ($this->internal) {
            return __('Internal note on :number: :title', $values);
        }

        return $to->isTenant()
            ? __(':author replied about your repair :number', $values)
            : __(':author replied on :number: :title', $values);
    }

    public function lines(TicketFacts $facts, TicketRecipient $to): array
    {
        $values = ['number' => $facts->number, 'title' => $facts->title, 'home' => $facts->home(), 'author' => $this->authorName($facts, $to)];

        $lines = [match (true) {
            $this->internal => __(':author added an internal note (staff only) to ticket :number, “:title” (:home). Open the ticket to read it.', $values),
            $to->isTenant() => __(':author wrote about your repair “:title” (:home).', $values),
            default => __(':author wrote on ticket :number, “:title” (:home).', $values),
        }];

        if ($this->excerpt !== null && ! $this->internal) {
            $lines[] = '“'.$this->excerpt.'”';
        }

        if ($this->attachmentCount > 0) {
            $lines[] = trans_choice('With :count file.|With :count files.', $this->attachmentCount, ['count' => $this->attachmentCount]);
        }

        return array_map(static fn (mixed $line): string => (string) $line, $lines);
    }

    private function authorName(TicketFacts $facts, TicketRecipient $to): string
    {
        return match ($this->author) {
            // The tenant hears from the agency, as on the portal's status notices.
            MessageAuthor::Staff => $to->isTenant() ? $facts->agency : ($facts->actorName ?? $facts->agency),
            MessageAuthor::Tenant => $facts->tenantName ?? $facts->actorName ?? (string) __('The tenant'),
            MessageAuthor::Owner => $facts->actorName !== null ? __(':name (the building\'s owner)', ['name' => $facts->actorName]) : (string) __('The building\'s owner'),
        };
    }
}
