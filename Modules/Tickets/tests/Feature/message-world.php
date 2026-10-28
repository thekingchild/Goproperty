<?php

declare(strict_types=1);

use App\Media\Media;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Properties\Models\Property;
use Modules\Tickets\Messages\TicketMessages;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Models\TicketMessage;
use Tests\Support\LivewireHttp;

/*
 * The thread's test world (P04-02), on the tickets' (ticket-world.php):
 * Palm Court belongs to Ngozi Eze, who follows it in /owner (owner
 * profile linked to her account); Oak Lodge belongs to Femi Bakare (his
 * own /owner account), so Ngozi must never see its tickets. Fixed texts
 * for what must NOT show: the internal note "STAFF-ONLY: landlord owes us
 * for the last plumber" and its file "internal-quote.pdf".
 */

require_once __DIR__.'/ticket-world.php';
require_once __DIR__.'/../../../Properties/tests/Feature/OwnerPortal/owner-world.php';
require_once __DIR__.'/../../../../tests/Support/media.php';

const INTERNAL_NOTE = 'STAFF-ONLY: landlord owes us for the last plumber';

function messageWorld(): void
{
    Storage::fake('media');
    ticketWorld();

    (function () {
        $this->ngozi = User::factory()->create(['name' => 'Ngozi Eze', 'email' => 'ngozi.eze@example.com', 'phone' => '+2348051112222']);
        $this->ngoziAtAcme = ownerIn($this->acme, ['type' => 'person', 'first_name' => 'Ngozi', 'last_name' => 'Eze', 'email' => 'ngozi.eze@example.com', 'phone' => '+2348051112222']);
        linkOwner($this->acme, $this->ngoziAtAcme, $this->ngozi);

        $this->femiOwner = User::factory()->create(['name' => 'Femi Bakare', 'email' => 'femi.bakare@example.com', 'phone' => '+2348059998888']);
        $this->femiAtAcme = ownerIn($this->acme, ['type' => 'person', 'first_name' => 'Femi', 'last_name' => 'Bakare', 'email' => 'femi.bakare@example.com', 'phone' => '+2348059998888']);
        linkOwner($this->acme, $this->femiAtAcme, $this->femiOwner);

        inOrganization($this->acme, function () {
            Property::query()->whereKey($this->palm->id)->firstOrFail()->forceFill(['owner_profile_id' => $this->ngoziAtAcme->id])->save();
            Property::query()->whereKey($this->oak->id)->firstOrFail()->forceFill(['owner_profile_id' => $this->femiAtAcme->id])->save();
        });
    })->call(test());
}

/** A member's reply (or internal note) through the writer. */
function staffReply(Organization $organization, User $by, Ticket $ticket, ?string $body, array $files = [], bool $internal = false): TicketMessage
{
    return inOrganization($organization, fn (): TicketMessage => app(TicketMessages::class)->reply($by, $ticket, $body, $files, $internal));
}

/** The tenant's reply through the writer. */
function tenantReply(Organization $organization, User $by, Ticket $ticket, ?string $body, array $files = []): TicketMessage
{
    return inOrganization($organization, fn (): TicketMessage => app(TicketMessages::class)->replyAsTenant($by, $ticket, $body, $files));
}

/** The owner's reply through the writer. */
function ownerReply(Organization $organization, User $by, Ticket $ticket, ?string $body, array $files = []): TicketMessage
{
    return inOrganization($organization, fn (): TicketMessage => app(TicketMessages::class)->replyAsOwner($by, $ticket, $body, $files));
}

/** Every message of the ticket as [author kind, body, internal], oldest first. */
function threadOf(Organization $organization, Ticket $ticket): array
{
    return inOrganization($organization, fn (): array => TicketMessage::query()->where('ticket_id', $ticket->id)->orderBy('id')->get()
        ->map(fn (TicketMessage $message): array => [$message->author_kind->value, $message->body, $message->is_internal])->all());
}

/** The media rows kept with the message, oldest first. */
function messageFiles(Organization $organization, TicketMessage $message): array
{
    return inOrganization($organization, fn (): array => Media::query()->where('model_type', $message->getMorphClass())->where('model_id', $message->id)->orderBy('id')->get()->all());
}

/** A small real photo as an upload (GD-made, so it decodes; a test file, so Livewire's test uploader takes it too). */
function ticketPhoto(string $name = 'leak.jpg', string $type = 'jpeg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents((string) mediaImage($name, 320, 240, $type)->getRealPath()));
}

/** An iPhone photo (HEIC) as an upload. */
function ticketHeic(string $name = 'IMG_0042.HEIC'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, heicBytes());
}

/** A PDF as an upload. */
function ticketPdf(string $name = 'plumber-quote.pdf'): UploadedFile
{
    return mediaPdf($name);
}

/** The snapshot of the page's Livewire component whose name contains $name (nested components too). */
function componentSnapshot(TestResponse|string $page, string $name): string
{
    $html = $page instanceof TestResponse ? (string) $page->getContent() : $page;
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);

        if (str_contains((string) (json_decode($snapshot, true)['memo']['name'] ?? ''), $name)) {
            return $snapshot;
        }
    }

    throw new LogicException("No Livewire component named like {$name} on the page.");
}

/** A Livewire update on a component of a page, as a fresh request by the same user. */
function threadUpdate(User $user, string $snapshot, array $updates = [], array $calls = []): TestResponse
{
    ticketsFreshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, $updates, $calls);
}

/** An owner portal page ($path after /owner/tickets), in a fresh request. */
function ownerTicketsPage(User $user, string $path = ''): TestResponse
{
    ticketsFreshRequest();

    return test()->actingAs($user)->get('/owner/tickets'.$path);
}
