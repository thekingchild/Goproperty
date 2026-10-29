<?php

declare(strict_types=1);

namespace Modules\Tickets\Messages;

use App\Media\Media;
use App\Media\MediaUploads;
use App\Media\UploadRefused;
use App\Media\UploadValidator;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\Moves\BuildingMoveFailed;
use App\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Tickets\Enums\MessageAuthor;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Events\TicketMessagePosted;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Models\TicketMessage;
use Modules\Tickets\Tickets\TicketRefused;
use Modules\Tickets\Tickets\TicketService;
use Throwable;

/**
 * The only writer of a ticket's thread (`ticket_messages`; ARCHITECTURE.md
 * § 6.6 "As built (P04-02)"): replies by the ticket's tenant (portal), a
 * member (workspace) or the building's owner (`/owner`), and members'
 * internal notes, which only staff ever see.
 *
 * Who: TicketPolicy (`reply` / `addInternalNote` for members holding
 * `tickets.respond`, `replyAsTenant`, `replyAsOwner`; `attachFiles` when
 * files come along, refused while GoProperty staff impersonate: evidence),
 * checked before anything is written. Then, in DB::transaction(…, 3) with
 * the ticket's row locked and re-read: a draft has no thread and "closed
 * ends the thread" (TicketRefused, nothing written); the owner may reply
 * only to a ticket shared with them (P04-06) and while no agent is
 * assigned (ARCHITECTURE.md § 5, both checked again on the locked row, so
 * an assignment or an unsharing made meanwhile wins). The message, its
 * files (App\Media\MediaUploads into the message's `ticket_attachments`
 * collection: JPEG, PNG, WebP, HEIC or PDF, 10 MB each, at most 5 a
 * message, every file checked from its bytes before anything is stored),
 * one audit entry (log `tickets`) and Events\TicketMessagePosted after the
 * commit. Messages and their files are evidence: never changed or deleted.
 *
 * A building move (P04-05) copies a ticket's public messages and their
 * files to the receiving organization's ticket (copyShared()); internal
 * notes never cross.
 *
 * Lock order, as TicketService: the ticket row first.
 */
final readonly class TicketMessages
{
    /** Files one message may carry (docs/plans/P04-subtasks.md default 7). */
    public const int MAX_FILES = 5;

    /** Characters of a message. */
    public const int BODY_MAX = 4000;

    public function __construct(
        private UploadValidator $validator,
        private MediaUploads $uploads,
        private OrganizationContext $context,
    ) {}

    /**
     * A building move (P04-05; docs/plans/P04-subtasks.md "Settled by the
     * product owner" 3): the public messages of $original (in $from) are
     * copied, oldest first, to $copy (the receiving organization's ticket,
     * in $to), each with its author, words and time, and its files as new
     * evidence of the copy (stored again through MediaUploads, checked from
     * their bytes, in $to's folder). **Internal notes never cross**: they
     * stay with the organization that wrote them. Nobody is told (no
     * TicketMessagePosted); one audit entry on the copy lists what was
     * copied. $stored is called with each new file as soon as it is
     * stored, so the move can remove it if it fails later. Runs inside the
     * handover's transaction.
     *
     * @param  Closure(Media): void  $stored
     * @return array{messages: int, files: int}
     *
     * @throws BuildingMoveFailed when a file cannot be read
     */
    public function copyShared(Ticket $original, Organization $from, Ticket $copy, Organization $to, Closure $stored): array
    {
        /** @var list<array{message: TicketMessage, files: list<Media>}> $thread */
        $thread = $this->context->run($from, static function () use ($original): array {
            $messages = TicketMessage::query()->shared()->where('ticket_id', $original->id)->orderBy('id')->get();
            $files = $messages->isEmpty() ? collect() : Media::query()
                ->where('model_type', (new TicketMessage)->getMorphClass())
                ->where('collection_name', TicketMessage::COLLECTION)
                ->whereIn('model_id', $messages->modelKeys())
                ->orderBy('order_column')->orderBy('id')
                ->get()
                ->groupBy('model_id');

            return array_values($messages->map(static fn (TicketMessage $message): array => [
                'message' => $message,
                'files' => array_values(($files[$message->id] ?? collect())->all()),
            ])->all());
        });

        if ($thread === []) {
            return ['messages' => 0, 'files' => 0];
        }

        return $this->context->run($to, function () use ($thread, $original, $copy, $stored): array {
            $copied = [];
            $fileCount = 0;

            foreach ($thread as ['message' => $source, 'files' => $files]) {
                $message = new TicketMessage;
                $message->ticket_id = $copy->id;
                $message->author_kind = $source->author_kind;
                $message->author_user_id = $source->author_user_id;
                $message->body = $source->body;
                $message->is_internal = false;
                // Kept at the time it was written, so the thread reads as it happened.
                $message->created_at = $source->created_at;
                $message->updated_at = $source->updated_at;
                $message->save();

                $kept = [];

                foreach ($files as $file) {
                    $media = $this->copyFile($file, $message, $copy, $original);
                    $stored($media);
                    $kept[] = ['media_id' => $media->id, 'copied_from_media_id' => $file->id, 'file_sha256' => (string) $media->checksum()];
                }

                if ($kept !== []) {
                    $message->attachment_count = count($kept);
                    $message->save();
                }

                $fileCount += count($kept);
                $copied[] = ['message_id' => $message->id, 'copied_from_message_id' => $source->id, 'attachments' => $kept];
            }

            activity(TicketService::LOG)
                ->event('ticket.thread_copied')
                ->performedOn($copy)
                ->withProperties([
                    'number' => $copy->number,
                    'moved_from_ticket_id' => $original->id,
                    'moved_from_number' => $original->number,
                    'messages' => $copied,
                ])
                ->log('Conversation copied with the ticket from its building\'s former organization');

            return ['messages' => count($copied), 'files' => $fileCount];
        });
    }

    /**
     * A member's reply (the tenant and the owner see it) or, with
     * $internal, an internal note (staff only).
     *
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public function reply(User $by, Ticket $ticket, ?string $body, array $files = [], bool $internal = false): TicketMessage
    {
        Gate::forUser($by)->authorize($internal ? 'addInternalNote' : 'reply', $ticket);

        return $this->post($by, $ticket, MessageAuthor::Staff, $body, $files, $internal);
    }

    /**
     * The ticket's tenant replies from the portal. Never internal.
     *
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public function replyAsTenant(User $by, Ticket $ticket, ?string $body, array $files = []): TicketMessage
    {
        Gate::forUser($by)->authorize('replyAsTenant', $ticket);

        return $this->post($by, $ticket, MessageAuthor::Tenant, $body, $files, false);
    }

    /**
     * The building's owner replies from /owner to a ticket shared with them
     * (P04-06), only while no agent is assigned. Never internal.
     *
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public function replyAsOwner(User $by, Ticket $ticket, ?string $body, array $files = []): TicketMessage
    {
        Gate::forUser($by)->authorize('replyAsOwner', $ticket);

        return $this->post($by, $ticket, MessageAuthor::Owner, $body, $files, false);
    }

    /**
     * The message as typed: line endings made \n, trailing spaces and runs
     * of blank lines tidied; null when empty.
     */
    public static function body(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = (string) preg_replace('/[ \t]+\n/u', "\n", $body);
        $body = trim((string) preg_replace('/\n{3,}/u', "\n\n", $body));

        return $body === '' ? null : $body;
    }

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException
     */
    private function post(User $by, Ticket $ticket, MessageAuthor $author, ?string $body, array $files, bool $internal): TicketMessage
    {
        $body = self::body($body);
        $this->validate($body, $files);

        if ($files !== []) {
            Gate::forUser($by)->authorize('attachFiles', $ticket);
        }

        return DB::transaction(function () use ($by, $ticket, $author, $body, $files, $internal): TicketMessage {
            $ticket = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ($ticket->status === TicketStatus::Draft) {
                throw TicketRefused::because(__('Send the ticket first: a draft has no conversation.'));
            }

            if ($ticket->status === TicketStatus::Closed) {
                throw TicketRefused::because(__('This ticket is closed, so its conversation has ended. Reopen it to write again.'));
            }

            if ($author === MessageAuthor::Owner && ! $ticket->sharedWithOwner()) {
                // Stopped sharing meanwhile (P04-06): the owner may no longer write here.
                throw TicketRefused::because(__('The agency no longer shares this repair with you.'));
            }

            if ($author === MessageAuthor::Owner && $ticket->assigned_to_user_id !== null) {
                throw TicketRefused::because(__('An agent of the agency is handling this ticket now, so the conversation is theirs.'));
            }

            $message = new TicketMessage;
            $message->ticket_id = $ticket->id;
            $message->author_kind = $author;
            $message->author_user_id = $by->id;
            $message->body = $body;
            $message->is_internal = $internal;
            $message->save();

            $stored = [];

            foreach ($files as $file) {
                $media = $this->uploads->add($message, TicketMessage::COLLECTION, $file, null, ['ticket_id' => $ticket->id, 'ticket_message_id' => $message->id]);
                $stored[] = ['media_id' => $media->id, 'file_sha256' => (string) $media->checksum(), 'mime_type' => (string) $media->mime_type];
            }

            if ($stored !== []) {
                $message->attachment_count = count($stored);
                $message->save();
            }

            activity(TicketService::LOG)
                ->event($internal ? 'ticket.note_added' : 'ticket.message_posted')
                ->performedOn($ticket)
                ->causedBy($by)
                ->withProperties([
                    'number' => $ticket->number,
                    'property_id' => $ticket->property_id,
                    'tenant_profile_id' => $ticket->tenant_profile_id,
                    'message_id' => $message->id,
                    'author' => $author->value,
                    'internal' => $internal,
                    'attachments' => $stored,
                ])
                ->log($internal ? 'Internal note added to the ticket' : 'Message posted on the ticket');

            event(new TicketMessagePosted(
                organizationId: $ticket->organization_id,
                ticketId: $ticket->id,
                number: (string) $ticket->number,
                messageId: $message->id,
                authorKind: $author->value,
                authorUserId: $by->id,
                internal: $internal,
                tenantProfileId: $ticket->tenant_profile_id,
                assignedToUserId: $ticket->assigned_to_user_id,
                propertyId: $ticket->property_id,
                attachmentCount: count($stored),
            ));

            return $message;
        }, 3);
    }

    /**
     * One file of a moved message, stored again as the copy's evidence (the
     * original stays where it is).
     *
     * @throws BuildingMoveFailed
     */
    private function copyFile(Media $file, TicketMessage $message, Ticket $copy, Ticket $original): Media
    {
        try {
            $bytes = Storage::disk($file->disk)->get($file->getPathRelativeToRoot());
        } catch (Throwable) {
            $bytes = null;
        }

        if (! is_string($bytes) || $bytes === '') {
            throw new BuildingMoveFailed(__('A file of ticket :number could not be read, so it could not be copied.', ['number' => $original->reference()]));
        }

        $path = tempnam(sys_get_temp_dir(), 'ticket-move-');

        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw new BuildingMoveFailed(__('A file of ticket :number could not be copied.', ['number' => $original->reference()]));
        }

        try {
            return $this->uploads->add($message, TicketMessage::COLLECTION, $path, $file->name.'.'.pathinfo($file->file_name, PATHINFO_EXTENSION), [
                'ticket_id' => $copy->id,
                'ticket_message_id' => $message->id,
                'copied_from_media_id' => $file->id,
            ]);
        } catch (UploadRefused $refused) {
            throw new BuildingMoveFailed(__('A file of ticket :number could not be copied: :reason', ['number' => $original->reference(), 'reason' => $refused->getMessage()]), previous: $refused);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Something to say (words or a file), within the limits; every file
     * checked from its bytes before anything is stored.
     *
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException
     */
    private function validate(?string $body, array $files): void
    {
        if ($body === null && $files === []) {
            throw ValidationException::withMessages(['body' => __('Write a message or add a photo.')]);
        }

        Validator::make(['body' => $body], ['body' => ['nullable', 'string', 'max:'.self::BODY_MAX]], [], ['body' => __('message')])->validate();

        if (count($files) > self::MAX_FILES) {
            throw ValidationException::withMessages(['files' => __('Add at most :count files to one message.', ['count' => self::MAX_FILES])]);
        }

        foreach ($files as $file) {
            try {
                $this->validator->check(TicketMessage::COLLECTION, $file);
            } catch (UploadRefused $refused) {
                throw ValidationException::withMessages(['files' => count($files) > 1
                    ? MediaUploads::label($file->getClientOriginalName()).': '.$refused->getMessage()
                    : $refused->getMessage()]);
            }
        }
    }
}
