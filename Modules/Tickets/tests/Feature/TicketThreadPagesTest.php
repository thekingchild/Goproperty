<?php

declare(strict_types=1);

use App\Media\Media;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Modules\Tickets\Livewire\Owner\OwnerTicketConversation;
use Modules\Tickets\Livewire\Portal\PortalTicketConversation;
use Modules\Tickets\Livewire\TicketConversation;
use Modules\Tickets\Models\TicketMessage;
use Modules\Tickets\Tickets\TicketService;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/message-world.php';

/*
 * P04-02: the conversation on the ticket pages (the "Conversation" slot
 * of P04-01's pages): staff reply, add internal notes and attach photos
 * and PDFs; the tenant replies with photos (HEIC too) from the portal;
 * the owner from /owner. Files open through the authorized routes only
 * (a redirect to a short-lived signed link). A closed ticket's
 * conversation has ended; a draft has none.
 */

beforeEach(function () {
    messageWorld();
    $this->ticket = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi sink leaking']);
});

afterEach(fn () => ticketsFreshRequest());

/** A conversation component as a Livewire test (uploads need Livewire's test client), as the user. */
function conversation(User $user, string $component, int $ticketId, mixed $organization = null): mixed
{
    ticketsFreshRequest();
    test()->actingAs($user);

    if ($organization !== null) {
        app(OrganizationContext::class)->set($organization);
    }

    return Livewire::test($component, ['ticketId' => $ticketId]);
}

/** Chooses files at the real time (Livewire's test uploader judges their age by the clock). */
function chooseMessageFiles(mixed $component, array $files): mixed
{
    $now = now();
    test()->travelBack();
    $component->set('files', $files);
    test()->travelTo($now);

    return $component;
}

it('lets staff reply with photos and a PDF, and add an internal note, from the ticket\'s page', function () {
    $page = conversation($this->agent, TicketConversation::class, $this->ticket->id, $this->acme)
        ->assertSee('No messages yet.')->assertSee('Internal note: only staff see it');

    chooseMessageFiles($page, [ticketPhoto('under-sink.jpg'), ticketPdf('plumber-quote.pdf')])
        ->set('body', 'A plumber comes tomorrow at 10.')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('Message sent.')
        ->assertSee('A plumber comes tomorrow at 10.')
        ->assertSee('plumber-quote')->assertSee('PDF ·')
        ->assertSet('body', '')->assertSet('files', []);

    $page->set('body', INTERNAL_NOTE)->set('internal', true)->call('send')
        ->assertHasNoErrors()->assertSee('Internal note added. Only staff see it.')->assertSee('Internal note: staff only')
        ->assertSet('internal', false);

    expect(threadOf($this->acme, $this->ticket))->toBe([
        ['staff', 'A plumber comes tomorrow at 10.', false],
        ['staff', INTERNAL_NOTE, true],
    ]);

    // The page lists the photo as a picture and the PDF as a file, through the workspace's file routes.
    $html = (string) ticketsPage($this->agent, "/{$this->ticket->id}")->assertOk()->getContent();
    expect($html)->toContain('data-test="ticket-conversation"')
        ->toMatch('#<img src="[^"]*/o/acme-homes/tickets/'.$this->ticket->id.'/files/\d+/thumb"#')
        ->toContain('plumber-quote');
});

it('lets the tenant reply with an iPhone photo and a PDF from the portal, the HEIC offered as a file', function () {
    staffReply($this->acme, $this->agent, $this->ticket, 'Can you send a photo of the pipe?');

    $page = conversation($this->kemi, PortalTicketConversation::class, $this->ticket->id)->assertSee('Can you send a photo of the pipe?');
    chooseMessageFiles($page, [ticketHeic('IMG_0042.HEIC'), ticketPdf('receipt.pdf')])
        ->set('body', 'Here it is.')
        ->call('send')
        ->assertHasNoErrors()->assertSee('Message sent.')->assertSee('IMG_0042')->assertSee('HEIC ·');

    $message = inOrganization($this->acme, fn () => TicketMessage::query()->latest('id')->firstOrFail());
    expect($message->author_kind->value)->toBe('tenant')
        ->and(array_map(fn (Media $media): string => $media->mime_type, messageFiles($this->acme, $message)))->toBe(['image/heic', 'application/pdf']);

    // A HEIC is not shown as a picture (browsers other than Safari cannot draw it).
    $html = (string) repairsPage($this->kemi, "/{$this->ticket->id}")->assertOk()->getContent();
    expect($html)->not->toMatch('#<img[^>]*IMG_0042#')->toContain('IMG_0042');
});

it('shows a refused file on the form and stores nothing', function () {
    $page = conversation($this->kemi, PortalTicketConversation::class, $this->ticket->id);
    chooseMessageFiles($page, [UploadedFile::fake()->createWithContent('leak.jpg', "<?php echo 'hi';")])
        ->set('body', 'See attached')
        ->call('send')
        ->assertHasErrors(['files'])
        ->assertSee('Upload a JPEG, PNG, WebP, HEIC or PDF file.');

    $page->set('files', [])->set('body', '')->call('send')->assertHasErrors(['body' => 'Write a message or add a photo.']);

    expect(threadOf($this->acme, $this->ticket))->toBe([])
        ->and(inOrganization($this->acme, fn () => Media::query()->count()))->toBe(0);
});

it('opens a file of the thread through a short-lived signed link, for each party allowed to see it', function () {
    $message = staffReply($this->acme, $this->agent, $this->ticket, 'Quote attached', [ticketPdf('plumber-quote.pdf'), ticketPhoto('pipe.jpg')]);
    [$pdf, $photo] = messageFiles($this->acme, $message);

    foreach ([
        [$this->agent, "/o/acme-homes/tickets/{$this->ticket->id}/files/{$pdf->id}"],
        [$this->kemi, "/portal/tickets/{$this->ticket->id}/files/{$pdf->id}"],
        [$this->ngozi, "/owner/tickets/{$this->ticket->id}/files/{$pdf->id}"],
    ] as [$user, $path]) {
        ticketsFreshRequest();
        $signed = (string) $this->actingAs($user)->get($path)->assertRedirect()->headers->get('Location');
        expect($signed)->toContain('/media/'.$this->acme->id.'/'.$pdf->uuid)->toContain('signature=');

        ticketsFreshRequest();
        $file = $this->get($signed)->assertOk();
        expect($file->headers->get('Content-Disposition'))->toStartWith('attachment');
    }

    // A thumbnail of a photo falls back to the original until the media queue made it; a PDF has none.
    ticketsFreshRequest();
    $this->actingAs($this->kemi)->get("/portal/tickets/{$this->ticket->id}/files/{$photo->id}/thumb")->assertRedirect();
    ticketsFreshRequest();
    $this->actingAs($this->kemi)->get("/portal/tickets/{$this->ticket->id}/files/{$pdf->id}/thumb")->assertNotFound();
});

it('ends the conversation when the ticket closes, shows none for a draft, and refreshes it when the page moves the ticket', function () {
    staffReply($this->acme, $this->agent, $this->ticket, 'Fixed the trap.');
    ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->agent, $this->ticket, 'Replaced the trap'));

    // The tenant confirms from the page: the conversation component is told to refresh.
    $show = LivewireHttp::snapshot(repairsPage($this->kemi, "/{$this->ticket->id}")->assertOk());
    $confirmed = threadUpdate($this->kemi, $show, [], [['confirm']])->assertOk();
    expect(json_encode($confirmed->json('components.0.effects.dispatches')))->toContain('ticket-changed');

    repairsPage($this->kemi, "/{$this->ticket->id}")->assertOk()->assertSee('Fixed the trap.')
        ->assertSee('data-test="thread-closed"', false)->assertDontSee('data-test="message-form"', false);
    ticketsPage($this->agent, "/{$this->ticket->id}")->assertOk()->assertSee('data-test="thread-closed"', false)->assertDontSee('data-test="message-form"', false);

    $draft = draftTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Door lock']);
    repairsPage($this->kemi, "/{$draft->id}")->assertOk()->assertDontSee('data-test="portal-ticket-conversation"', false);
});

it('limits how many messages one person sends a minute', function () {
    RateLimiter::clear('ticket-messages:'.$this->kemi->id);
    $page = conversation($this->kemi, PortalTicketConversation::class, $this->ticket->id);

    foreach (range(1, 20) as $i) {
        $page->set('body', "Message {$i}")->call('send')->assertHasNoErrors();
    }

    $page->set('body', 'One too many')->call('send')->assertHasErrors(['body']);
    expect(threadOf($this->acme, $this->ticket))->toHaveCount(20);
});

it('lets the owner reply from the page while nobody is assigned, and never autofocuses a field on the portal pages', function () {
    conversation($this->ngozi, OwnerTicketConversation::class, $this->ticket->id)
        ->set('body', 'The plumber I use is on his way.')
        ->call('send')
        ->assertHasNoErrors()->assertSee('Message sent.')->assertSee('The plumber I use is on his way.');

    foreach ([
        repairsPage($this->kemi, "/{$this->ticket->id}"),
        ownerTicketsPage($this->ngozi, "/{$this->ticket->id}"),
        ownerTicketsPage($this->ngozi),
    ] as $page) {
        expect((string) $page->assertOk()->getContent())->not->toContain('autofocus');
    }
});
