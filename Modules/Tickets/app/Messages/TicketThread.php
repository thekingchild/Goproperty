<?php

declare(strict_types=1);

namespace Modules\Tickets\Messages;

use App\Media\CollectionRules;
use App\Media\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Models\TicketMessage;

/**
 * A ticket's thread as its pages read it (P04-02), inside the ticket's
 * organization: the messages oldest first, each with its author's name
 * (Core's users table, one lookup a page) and its files (one media query a
 * page). What a reader gets depends on who reads (ThreadViewer): internal
 * notes, and their files, are left out of the query itself for the tenant
 * and the owner (ARCHITECTURE.md § 6.6: "staff see internal notes the
 * tenant never sees, enforced server-side"). Reads only; who may read the
 * ticket at all is the caller's policy check.
 */
final readonly class TicketThread
{
    /** At most this many messages a page shows (the newest; a ticket rarely has more than a few dozen). */
    public const int LIMIT = 300;

    /** @return list<ThreadMessage> oldest first */
    public function of(Ticket $ticket, ThreadViewer $viewer, ?int $you = null): array
    {
        $messages = $this->visible($ticket, $viewer)->orderByDesc('id')->limit(self::LIMIT)->get()->reverse()->values();

        if ($messages->isEmpty()) {
            return [];
        }

        $userIds = $messages->pluck('author_user_id')->filter()->unique()->values()->all();
        $names = $userIds === [] ? [] : User::query()->whereKey($userIds)->pluck('name', 'id')->all();
        $files = [];

        foreach ($this->media($messages->map(static fn (TicketMessage $message): int => $message->id)->values()->all()) as $media) {
            $files[(int) $media->model_id][] = new ThreadAttachment(
                mediaId: $media->id,
                name: (string) $media->name,
                mimeType: (string) $media->mime_type,
                size: (int) $media->size,
                picture: in_array($media->mime_type, CollectionRules::DECODABLE_IMAGES, true) || $media->hasGeneratedConversion('thumb'),
            );
        }

        return array_values($messages->map(static fn (TicketMessage $message): ThreadMessage => new ThreadMessage(
            id: $message->id,
            author: $message->author_kind,
            authorName: match (true) {
                $you !== null && $message->author_user_id === $you => (string) __('You'),
                $message->author_user_id === null => (string) __('Former user'),
                default => (string) ($names[$message->author_user_id] ?? __('Former user')),
            },
            mine: $you !== null && $message->author_user_id === $you,
            internal: $message->is_internal,
            body: $message->body,
            postedAt: $message->created_at,
            attachments: $files[$message->id] ?? [],
        ))->all());
    }

    /** How many messages the reader would see. */
    public function count(Ticket $ticket, ThreadViewer $viewer): int
    {
        return $this->visible($ticket, $viewer)->count();
    }

    /**
     * The file $mediaId of a message of the ticket the reader may see, or
     * null (the file routes answer 404): another ticket's file, an
     * internal note's file for the tenant or the owner, anything else.
     */
    public function attachment(Ticket $ticket, int $mediaId, ThreadViewer $viewer): ?Media
    {
        $media = Media::query()->whereKey($mediaId)
            ->where('model_type', (new TicketMessage)->getMorphClass())
            ->where('collection_name', TicketMessage::COLLECTION)
            ->whereIn('model_id', $this->visible($ticket, $viewer)->select('id'))
            ->first();

        return $media instanceof Media ? $media : null;
    }

    /** @return Builder<TicketMessage> */
    private function visible(Ticket $ticket, ThreadViewer $viewer): Builder
    {
        return TicketMessage::query()->where('ticket_id', $ticket->id)
            ->when(! $viewer->seesInternalNotes(), static fn (Builder $query) => $query->where('is_internal', false));
    }

    /**
     * @param  array<int, int>  $messageIds
     * @return iterable<Media>
     */
    private function media(array $messageIds): iterable
    {
        return Media::query()
            ->where('model_type', (new TicketMessage)->getMorphClass())
            ->where('collection_name', TicketMessage::COLLECTION)
            ->whereIn('model_id', $messageIds)
            ->orderBy('order_column')->orderBy('id')
            ->get();
    }
}
