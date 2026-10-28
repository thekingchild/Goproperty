<?php

declare(strict_types=1);

namespace Modules\Tickets\Messages;

use Carbon\CarbonImmutable;
use Modules\Tickets\Enums\MessageAuthor;

/**
 * One message as a ticket page shows it (TicketThread): who wrote it (a
 * name, or "You"), as which party, when, the words and the files. Plain
 * values; `internal` is only ever true on the staff page.
 */
final readonly class ThreadMessage
{
    /** @param  list<ThreadAttachment>  $attachments */
    public function __construct(
        public int $id,
        public MessageAuthor $author,
        public string $authorName,
        public bool $mine,
        public bool $internal,
        public ?string $body,
        public ?CarbonImmutable $postedAt,
        public array $attachments,
    ) {}
}
