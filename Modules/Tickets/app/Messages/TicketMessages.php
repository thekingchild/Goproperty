<?php

declare(strict_types=1);

namespace Modules\Tickets\Messages;

use App\Media\MediaUploads;
use App\Media\UploadRefused;
use App\Media\UploadValidator;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Tickets\Enums\MessageAuthor;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Events\TicketMessagePosted;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Models\TicketMessage;
use Modules\Tickets\Tickets\TicketRefused;
use Modules\Tickets\Tickets\TicketService;

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
 * only while no agent is assigned (ARCHITECTURE.md § 5, checked again on
 * the locked row, so an assignment made meanwhile wins). The message, its
 * files (App\Media\MediaUploads into the message's `ticket_attachments`
 * collection: JPEG, PNG, WebP, HEIC or PDF, 10 MB each, at most 5 a
 * message, every file checked from its bytes before anything is stored),
 * one audit entry (log `tickets`) and Events\TicketMessagePosted after the
 * commit. Messages and their files are evidence: never changed or deleted.
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
    ) {}

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
     * The building's owner replies from /owner, only while no agent is
     * assigned. Never internal.
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
