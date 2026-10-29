<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Media\EvidenceFileProtected;
use App\Media\Media;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Tickets\Events\TicketMessagePosted;
use Modules\Tickets\Messages\TicketMessages;
use Modules\Tickets\Models\TicketMessage;
use Modules\Tickets\Tickets\TicketRefused;
use Modules\Tickets\Tickets\TicketService;

require_once __DIR__.'/message-world.php';

/*
 * P04-02: the thread of a ticket (ARCHITECTURE.md § 6.6 "As built
 * (P04-02)"; DATABASE_SCHEMA.md § 5 `ticket_messages`). Messages are
 * written only by Messages\TicketMessages: the ticket's row locked, a
 * draft and a closed ticket refused ("closed ends the thread"), one row,
 * one audit entry and TicketMessagePosted after the commit per message.
 * Attachments are evidence: JPEG, PNG, WebP, HEIC or PDF, 10 MB each, at
 * most 5 a message, checked from their bytes, never deleted.
 */

beforeEach(function () {
    messageWorld();
    $this->ticket = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi sink leaking']);
    // The owner sees only what the agency shares with them (P04-06).
    $this->ticket = shareTicket($this->acme, $this->owner, $this->ticket);
});

afterEach(fn () => ticketsFreshRequest());

it('keeps the tenant, staff and the owner in one thread, oldest first, with one audit entry and one event per message', function () {
    $events = [];
    Event::listen(TicketMessagePosted::class, function (TicketMessagePosted $event) use (&$events): void {
        $events[] = $event;
    });
    ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $this->ticket, $this->agent->id));
    ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $this->ticket, null));

    $first = tenantReply($this->acme, $this->kemi, $this->ticket, "  It is dripping again.\r\n\r\n\r\n\r\nPlease come today.  ");
    $owner = ownerReply($this->acme, $this->ngozi, $this->ticket, 'I have asked the agency to send a plumber.');
    $note = staffReply($this->acme, $this->agent, $this->ticket, INTERNAL_NOTE, internal: true);
    $reply = staffReply($this->acme, $this->agent, $this->ticket, 'A plumber comes tomorrow at 10.');

    expect(threadOf($this->acme, $this->ticket))->toBe([
        ['tenant', "It is dripping again.\n\nPlease come today.", false],
        ['owner', 'I have asked the agency to send a plumber.', false],
        ['staff', INTERNAL_NOTE, true],
        ['staff', 'A plumber comes tomorrow at 10.', false],
    ])
        ->and($first->author_user_id)->toBe($this->kemi->id)
        ->and($owner->author_user_id)->toBe($this->ngozi->id)
        ->and(AuditEntry::query()->where('event', 'ticket.message_posted')->count())->toBe(3)
        ->and(AuditEntry::query()->where('event', 'ticket.note_added')->sole()->properties->toArray())->toMatchArray(['message_id' => $note->id, 'internal' => true, 'author' => 'staff', 'number' => 'TKT-000001'])
        ->and(array_map(fn (TicketMessagePosted $e): array => [$e->messageId, $e->authorKind, $e->authorUserId, $e->internal], $events))->toBe([
            [$first->id, 'tenant', $this->kemi->id, false],
            [$owner->id, 'owner', $this->ngozi->id, false],
            [$note->id, 'staff', $this->agent->id, true],
            [$reply->id, 'staff', $this->agent->id, false],
        ])
        ->and($events[3])->toEqual(new TicketMessagePosted(
            organizationId: $this->acme->id,
            ticketId: $this->ticket->id,
            number: 'TKT-000001',
            messageId: $reply->id,
            authorKind: 'staff',
            authorUserId: $this->agent->id,
            internal: false,
            tenantProfileId: $this->kemiAtAcme->id,
            assignedToUserId: null,
            propertyId: $this->palm->id,
            attachmentCount: 0,
        ));
});

it('announces a message only after it commits, and a message rolled back leaves nothing', function () {
    $seen = 0;
    Event::listen(TicketMessagePosted::class, function () use (&$seen): void {
        $seen++;
    });

    try {
        DB::transaction(function (): void {
            staffReply($this->acme, $this->agent, $this->ticket, 'Rolled back', [ticketPhoto()]);
            throw new DomainException('Something after the message failed.');
        });
    } catch (DomainException) {
    }

    expect($seen)->toBe(0)
        ->and(threadOf($this->acme, $this->ticket))->toBe([])
        ->and(inOrganization($this->acme, fn () => Media::query()->count()))->toBe(0);

    staffReply($this->acme, $this->agent, $this->ticket, 'Kept');
    expect($seen)->toBe(1);
});

it('acceptance: refuses a message on a closed ticket and on a draft, writing nothing', function () {
    ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->agent, $this->ticket));
    // Resolved is still open for words: "it is not fixed" or "thank you".
    tenantReply($this->acme, $this->kemi, $this->ticket, 'Thank you, it works.');
    ticketsDo($this->acme, fn (TicketService $s) => $s->confirmResolution($this->kemi, $this->ticket));
    $draft = draftTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Door lock']);
    $audits = AuditEntry::query()->count();

    expect(fn () => tenantReply($this->acme, $this->kemi, $this->ticket, 'One more thing'))->toThrow(TicketRefused::class, 'This ticket is closed, so its conversation has ended.')
        ->and(fn () => staffReply($this->acme, $this->owner, $this->ticket, 'Closing remark', [ticketPhoto()]))->toThrow(TicketRefused::class, 'This ticket is closed')
        ->and(fn () => staffReply($this->acme, $this->owner, $this->ticket, 'Note', internal: true))->toThrow(TicketRefused::class, 'This ticket is closed')
        ->and(fn () => ownerReply($this->acme, $this->ngozi, $this->ticket, 'Owner remark'))->toThrow(TicketRefused::class, 'This ticket is closed')
        ->and(fn () => tenantReply($this->acme, $this->kemi, $draft, 'Hello?'))->toThrow(TicketRefused::class, 'Send the ticket first: a draft has no conversation.')
        ->and(threadOf($this->acme, $this->ticket))->toBe([['tenant', 'Thank you, it works.', false]])
        ->and(threadOf($this->acme, $draft))->toBe([])
        ->and(AuditEntry::query()->count())->toBe($audits)
        ->and(inOrganization($this->acme, fn () => Media::query()->count()))->toBe(0);

    // Reopened, the conversation goes on.
    ticketsDo($this->acme, fn (TicketService $s) => $s->reopenAsTenant($this->kemi, $this->ticket, 'Dripping again'));
    tenantReply($this->acme, $this->kemi, $this->ticket, 'It started again tonight.');
    expect(count(threadOf($this->acme, $this->ticket)))->toBe(2);
});

it('needs words or a file, and keeps a message within 4,000 characters', function () {
    expect(fn () => tenantReply($this->acme, $this->kemi, $this->ticket, "  \n "))->toThrow(ValidationException::class, 'Write a message or add a photo.')
        ->and(fn () => tenantReply($this->acme, $this->kemi, $this->ticket, str_repeat('a', TicketMessages::BODY_MAX + 1)))->toThrow(ValidationException::class, 'The message field must not be greater than 4000 characters.');

    $photoOnly = tenantReply($this->acme, $this->kemi, $this->ticket, null, [ticketPhoto()]);
    expect($photoOnly->body)->toBeNull()->and($photoOnly->attachment_count)->toBe(1);
});

describe('attachments', function () {
    it('acceptance: keeps JPEG, PNG, WebP, HEIC and PDF files with a message as evidence, checked from their bytes', function () {
        $message = tenantReply($this->acme, $this->kemi, $this->ticket, 'Photos of the leak and the plumber\'s quote', [
            ticketPhoto('under-sink.jpg'),
            ticketPhoto('cabinet.png', 'png'),
            ticketPhoto('floor.webp', 'webp'),
            ticketHeic('IMG_0042.HEIC'),
            ticketPdf('plumber-quote.pdf'),
        ]);
        $files = messageFiles($this->acme, $message);

        expect(array_map(fn (Media $media): array => [$media->collection_name, $media->mime_type, $media->name], $files))->toBe([
            ['ticket_attachments', 'image/jpeg', 'under-sink'],
            ['ticket_attachments', 'image/png', 'cabinet'],
            ['ticket_attachments', 'image/webp', 'floor'],
            ['ticket_attachments', 'image/heic', 'IMG_0042'],
            ['ticket_attachments', 'application/pdf', 'plumber-quote'],
        ])
            ->and($message->fresh()?->attachment_count)->toBe(5)
            ->and($files[3]->checksum())->toBe(hash('sha256', heicBytes()))
            ->and(AuditEntry::query()->where('event', 'ticket.message_posted')->sole()->properties['attachments'])->toHaveCount(5);

        // Evidence (AGENT.md § 3 rule 7): neither the files nor the message can be deleted or changed.
        expect(fn () => inOrganization($this->acme, fn () => $files[0]->delete()))->toThrow(EvidenceFileProtected::class)
            ->and(fn () => inOrganization($this->acme, fn () => TicketMessage::query()->findOrFail($message->id)->delete()))->toThrow(LogicException::class, 'never deleted')
            ->and(fn () => inOrganization($this->acme, fn () => TicketMessage::query()->findOrFail($message->id)->forceFill(['body' => 'Edited', 'is_internal' => true])->save()))->toThrow(LogicException::class, 'never changed')
            ->and(inOrganization($this->acme, fn () => Media::query()->count()))->toBe(5);
    });

    it('acceptance: refuses a file of another type, one over 10 MB, and a sixth file, storing nothing', function (Closure $files, string $error) {
        $audits = AuditEntry::query()->count();

        expect(fn () => tenantReply($this->acme, $this->kemi, $this->ticket, 'Look at this', $files()))->toThrow(ValidationException::class, $error)
            ->and(threadOf($this->acme, $this->ticket))->toBe([])
            ->and(inOrganization($this->acme, fn () => Media::query()->count()))->toBe(0)
            ->and(AuditEntry::query()->count())->toBe($audits);
    })->with([
        'a script named .jpg' => [fn () => [UploadedFile::fake()->createWithContent('leak.jpg', "<?php echo 'hi';")], 'Upload a JPEG, PNG, WebP, HEIC or PDF file. This file is not one, whatever its name says.'],
        'an SVG' => [fn () => [UploadedFile::fake()->createWithContent('leak.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')], 'SVG files are not accepted.'],
        'a Word document' => [fn () => [UploadedFile::fake()->createWithContent('quote.docx', "PK\x03\x04".str_repeat('x', 200))], 'Upload a JPEG, PNG, WebP, HEIC or PDF file.'],
        'a video' => [fn () => [UploadedFile::fake()->createWithContent('leak.mov', "\0\0\0\x14ftypqt  \0\0\0\0qt  ".str_repeat('x', 200))], 'Upload a JPEG, PNG, WebP, HEIC or PDF file.'],
        'a photo over 10 MB' => [fn () => [UploadedFile::fake()->createWithContent('huge.jpg', str_repeat('x', 10 * 1024 * 1024 + 1))], 'The file is larger than 10 MB.'],
        'six files' => [fn () => array_map(fn (int $i): UploadedFile => ticketPhoto("photo-{$i}.jpg"), range(1, 6)), 'Add at most 5 files to one message.'],
        'five good files and a bad one' => [fn () => [ticketPhoto('a.jpg'), ticketPhoto('b.jpg'), ticketPdf(), UploadedFile::fake()->createWithContent('notes.txt', 'just text')], 'notes: Upload a JPEG, PNG, WebP, HEIC or PDF file.'],
    ]);

    it('takes a file of exactly 10 MB and five files in one message', function () {
        $pdf = "%PDF-1.4\n".str_repeat('0', 10 * 1024 * 1024 - 9);
        $message = staffReply($this->acme, $this->agent, $this->ticket, null, [UploadedFile::fake()->createWithContent('scan.pdf', $pdf), ticketPhoto('1.jpg'), ticketPhoto('2.jpg'), ticketPhoto('3.jpg'), ticketHeic()]);

        expect($message->attachment_count)->toBe(5)
            ->and(messageFiles($this->acme, $message)[0]->size)->toBe(10 * 1024 * 1024);
    });
});

it('refuses the wrong person at the writer: another tenant, a property owner member, a narrowed agent elsewhere, another organization', function () {
    $oakTicket = raiseTicket($this->acme, $this->owner, $this->oakUnit, $this->femi, ['title' => 'Oak pump']);
    $foreign = raiseTicket($this->other, $this->otherOwner, $this->foreignUnit, $this->foreignTenant, ['title' => 'Foreign roof']);
    $propertyOwnerMember = joinAs(User::factory()->create(['name' => 'Workspace Owner Role']), $this->acme, OrganizationRole::PropertyOwner);

    expect(fn () => tenantReply($this->acme, $this->tunde, $this->ticket, 'Not my ticket'))->toThrow(AuthorizationException::class)
        ->and(fn () => staffReply($this->acme, $this->kemi, $this->ticket, 'Tenant posing as staff'))->toThrow(AuthorizationException::class)
        ->and(fn () => staffReply($this->acme, $this->kemi, $this->ticket, 'Tenant writing a note', internal: true))->toThrow(AuthorizationException::class)
        ->and(fn () => staffReply($this->acme, $this->ngozi, $this->ticket, 'Owner writing a note', internal: true))->toThrow(AuthorizationException::class)
        ->and(fn () => staffReply($this->acme, $propertyOwnerMember, $this->ticket, 'Hello'))->toThrow(AuthorizationException::class)
        ->and(fn () => staffReply($this->acme, $this->agent, $oakTicket, 'Bola is Palm Court only'))->toThrow(AuthorizationException::class)
        ->and(fn () => ownerReply($this->acme, $this->ngozi, $oakTicket, 'Not her building'))->toThrow(AuthorizationException::class)
        ->and(fn () => ownerReply($this->acme, $this->femiOwner, $this->ticket, 'Not his building'))->toThrow(AuthorizationException::class)
        ->and(fn () => staffReply($this->acme, $this->owner, $foreign, 'Another organization'))->toThrow(AuthorizationException::class)
        ->and(fn () => staffReply($this->other, $this->owner, $foreign, 'Not a member there'))->toThrow(AuthorizationException::class)
        ->and(threadOf($this->acme, $this->ticket))->toBe([])
        ->and(threadOf($this->acme, $oakTicket))->toBe([])
        ->and(threadOf($this->other, $foreign))->toBe([]);
});
