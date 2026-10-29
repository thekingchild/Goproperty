<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\Tickets\Contracts\TicketService as TicketServiceContract;
use Modules\Tickets\Messages\ThreadViewer;
use Modules\Tickets\Messages\TicketThread;
use Modules\Tickets\Models\Ticket;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/message-world.php';
require_once __DIR__.'/../../../../tests/Feature/Exports/helpers.php';

/*
 * P04-02 acceptance: "Staff see internal notes the tenant never sees
 * (enforced server-side)". The note and its file are left out of the
 * tenant's and the owner's queries (Messages\TicketThread), so they reach
 * no page, no Livewire payload (first render or any update, crafted ones
 * included), no file link, no export and no contract; no ability lets a
 * tenant or an owner write one. Fixed texts: INTERNAL_NOTE and the file
 * "internal-quote.pdf".
 */

beforeEach(function () {
    messageWorld();
    $this->ticket = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi sink leaking']);
    // The owner sees only what the agency shares with them (P04-06).
    $this->ticket = shareTicket($this->acme, $this->owner, $this->ticket);
    $this->shared = staffReply($this->acme, $this->agent, $this->ticket, 'A plumber comes tomorrow at 10.', [ticketPhoto('plumber-visit.jpg')]);
    $this->note = staffReply($this->acme, $this->agent, $this->ticket, INTERNAL_NOTE, [ticketPdf('internal-quote.pdf')], internal: true);
    $this->noteFile = messageFiles($this->acme, $this->note)[0];
    $this->sharedFile = messageFiles($this->acme, $this->shared)[0];
});

afterEach(fn () => ticketsFreshRequest());

/** Asserts a response or string carries neither the note nor its file. */
function expectNoInternalNote(string $content): void
{
    expect($content)->not->toContain('STAFF-ONLY')->not->toContain('landlord owes us')->not->toContain('internal-quote')
        ->not->toContain('Internal note')->not->toContain('data-internal="1"')
        ->not->toContain('files/'.test()->noteFile->id);
}

it('acceptance: shows staff the internal note, marked, and never the tenant or the owner, on any page', function () {
    $staff = ticketsPage($this->agent, "/{$this->ticket->id}")->assertOk();
    expect((string) $staff->getContent())->toContain(INTERNAL_NOTE)->toContain('internal-quote')->toContain('data-internal="1"')->toContain('Internal note: staff only')
        ->toContain('A plumber comes tomorrow at 10.');
    foreach ([$this->owner, $this->admin, $this->freeAgent] as $member) {
        expect((string) ticketsPage($member, "/{$this->ticket->id}")->assertOk()->getContent())->toContain(INTERNAL_NOTE);
    }

    $portal = repairsPage($this->kemi, "/{$this->ticket->id}")->assertOk()->assertSee('A plumber comes tomorrow at 10.')->assertSee('plumber-visit');
    expectNoInternalNote((string) $portal->getContent());
    expectNoInternalNote((string) repairsPage($this->kemi)->assertOk()->getContent());

    $owner = ownerTicketsPage($this->ngozi, "/{$this->ticket->id}")->assertOk()->assertSee('A plumber comes tomorrow at 10.');
    expectNoInternalNote((string) $owner->getContent());
    expectNoInternalNote((string) ownerTicketsPage($this->ngozi)->assertOk()->getContent());
});

it('acceptance: never puts an internal note in the tenant\'s or the owner\'s Livewire payload, whatever the request', function () {
    // The tenant's conversation: its snapshot, a refresh, a reply, and crafted updates.
    $page = repairsPage($this->kemi, "/{$this->ticket->id}")->assertOk();
    $snapshot = componentSnapshot($page, 'portal-ticket-conversation');
    expectNoInternalNote($snapshot);

    $refreshed = threadUpdate($this->kemi, $snapshot, [], [['$refresh']])->assertOk();
    expectNoInternalNote((string) $refreshed->getContent());

    $sent = threadUpdate($this->kemi, $snapshot, ['body' => 'Thank you, I will be home.'], [['send']])->assertOk();
    expectNoInternalNote((string) $sent->getContent());
    expect(LivewireHttp::html($sent))->toContain('Thank you, I will be home.');

    // Asking for the note as if staff: no such property or method on the tenant's component; nothing is written.
    expect(threadUpdate($this->kemi, $snapshot, ['internal' => true, 'body' => 'Sneaky'], [['send']])->status())->toBeGreaterThanOrEqual(400)
        ->and(threadUpdate($this->kemi, $snapshot, [], [['render']])->status())->toBeGreaterThanOrEqual(400);

    // The staff component, called with the tenant's session: the workspace refuses her.
    $staffSnapshot = componentSnapshot(ticketsPage($this->agent, "/{$this->ticket->id}")->assertOk(), 'ticket-conversation');
    $asTenant = threadUpdate($this->kemi, $staffSnapshot, [], [['$refresh']]);
    expect($asTenant->status())->toBeIn([403, 404]);
    expectNoInternalNote((string) $asTenant->getContent());

    // The owner's conversation likewise.
    $ownerSnapshot = componentSnapshot(ownerTicketsPage($this->ngozi, "/{$this->ticket->id}")->assertOk(), 'owner-ticket-conversation');
    expectNoInternalNote($ownerSnapshot);
    expectNoInternalNote((string) threadUpdate($this->ngozi, $ownerSnapshot, [], [['$refresh']])->assertOk()->getContent());
    expect(threadUpdate($this->ngozi, $ownerSnapshot, ['internal' => true, 'body' => 'Sneaky'], [['send']])->status())->toBeGreaterThanOrEqual(400);

    expect(array_column(threadOf($this->acme, $this->ticket), 2))->toBe([false, true, false]);
});

it('acceptance: never lets the tenant or the owner open an internal note\'s file, whatever id they send', function () {
    // The shared photo opens for both; the note's file answers 404, on the file and the thumbnail routes.
    ticketsFreshRequest();
    $this->actingAs($this->kemi)->get("/portal/tickets/{$this->ticket->id}/files/{$this->sharedFile->id}")->assertRedirect();
    foreach (['', '/thumb'] as $suffix) {
        ticketsFreshRequest();
        $this->actingAs($this->kemi)->get("/portal/tickets/{$this->ticket->id}/files/{$this->noteFile->id}{$suffix}")->assertNotFound();
        ticketsFreshRequest();
        $this->actingAs($this->ngozi)->get("/owner/tickets/{$this->ticket->id}/files/{$this->noteFile->id}{$suffix}")->assertNotFound();
    }
    ticketsFreshRequest();
    $this->actingAs($this->ngozi)->get("/owner/tickets/{$this->ticket->id}/files/{$this->sharedFile->id}")->assertRedirect();

    // Staff open both.
    ticketsFreshRequest();
    $this->actingAs($this->agent)->get("/o/acme-homes/tickets/{$this->ticket->id}/files/{$this->noteFile->id}")->assertRedirect();

    // Another ticket's id with this ticket's file, and the reader's own query: nothing.
    $tunde = tenantTicket($this->acme, $this->tunde, $this->tundeLease, ['title' => 'Tunde socket']);
    ticketsFreshRequest();
    $this->actingAs($this->tunde)->get("/portal/tickets/{$tunde->id}/files/{$this->sharedFile->id}")->assertNotFound();
    ticketsFreshRequest();
    $this->actingAs($this->tunde)->get("/portal/tickets/{$this->ticket->id}/files/{$this->sharedFile->id}")->assertNotFound();

    expect(inOrganization($this->acme, fn () => app(TicketThread::class)->attachment($this->ticket, $this->noteFile->id, ThreadViewer::Tenant)))->toBeNull()
        ->and(inOrganization($this->acme, fn () => app(TicketThread::class)->attachment($this->ticket, $this->noteFile->id, ThreadViewer::Owner)))->toBeNull()
        ->and(inOrganization($this->acme, fn () => app(TicketThread::class)->attachment($this->ticket, $this->noteFile->id, ThreadViewer::Staff)?->id))->toBe($this->noteFile->id)
        ->and(inOrganization($this->acme, fn () => app(TicketThread::class)->count($this->ticket, ThreadViewer::Tenant)))->toBe(1)
        ->and(inOrganization($this->acme, fn () => app(TicketThread::class)->count($this->ticket, ThreadViewer::Staff)))->toBe(2);
});

it('acceptance: keeps internal notes out of the tickets export and Contracts\TicketService', function () {
    $rows = exportRows($this->owner, '/tickets', 'tickets');
    expect(json_encode($rows))->not->toContain('STAFF-ONLY')->not->toContain('internal-quote')->toContain('Kemi sink leaking');

    $summary = inOrganization($this->acme, fn () => app(TicketServiceContract::class)->find($this->ticket->id, $this->owner));
    $forLease = inOrganization($this->acme, fn () => app(TicketServiceContract::class)->forLease($this->lease->id));
    expect(json_encode([$summary, $forLease]))->not->toContain('STAFF-ONLY')->not->toContain('internal');
});

it('acceptance: lets staff who see the ticket see its notes, and no ability lets a tenant or an owner see or write one', function () {
    $abilities = fn (User $user): array => inOrganization($this->acme, fn (): array => [
        'viewInternalNotes' => Gate::forUser($user)->allows('viewInternalNotes', $this->ticket),
        'addInternalNote' => Gate::forUser($user)->allows('addInternalNote', $this->ticket),
        'reply' => Gate::forUser($user)->allows('reply', $this->ticket),
        'replyAsTenant' => Gate::forUser($user)->allows('replyAsTenant', $this->ticket),
        'replyAsOwner' => Gate::forUser($user)->allows('replyAsOwner', $this->ticket),
        'viewAsOwner' => Gate::forUser($user)->allows('viewAsOwner', $this->ticket),
    ]);

    expect($abilities($this->agent))->toBe(['viewInternalNotes' => true, 'addInternalNote' => true, 'reply' => true, 'replyAsTenant' => false, 'replyAsOwner' => false, 'viewAsOwner' => false])
        ->and($abilities($this->owner))->toBe(['viewInternalNotes' => true, 'addInternalNote' => true, 'reply' => true, 'replyAsTenant' => false, 'replyAsOwner' => false, 'viewAsOwner' => false])
        ->and($abilities($this->kemi))->toBe(['viewInternalNotes' => false, 'addInternalNote' => false, 'reply' => false, 'replyAsTenant' => true, 'replyAsOwner' => false, 'viewAsOwner' => false])
        ->and($abilities($this->ngozi))->toBe(['viewInternalNotes' => false, 'addInternalNote' => false, 'reply' => false, 'replyAsTenant' => false, 'replyAsOwner' => true, 'viewAsOwner' => true])
        ->and($abilities($this->femiOwner))->toBe(['viewInternalNotes' => false, 'addInternalNote' => false, 'reply' => false, 'replyAsTenant' => false, 'replyAsOwner' => false, 'viewAsOwner' => false]);

    // The tenant's and the owner's readers never return a note, whatever is asked.
    expect(array_map(fn ($m) => $m->body, inOrganization($this->acme, fn () => app(TicketThread::class)->of(Ticket::query()->findOrFail($this->ticket->id), ThreadViewer::Tenant))))->toBe(['A plumber comes tomorrow at 10.'])
        ->and(array_map(fn ($m) => $m->body, inOrganization($this->acme, fn () => app(TicketThread::class)->of(Ticket::query()->findOrFail($this->ticket->id), ThreadViewer::Owner))))->toBe(['A plumber comes tomorrow at 10.'])
        ->and(array_map(fn ($m) => [$m->body, $m->internal], inOrganization($this->acme, fn () => app(TicketThread::class)->of(Ticket::query()->findOrFail($this->ticket->id), ThreadViewer::Staff))))->toBe([['A plumber comes tomorrow at 10.', false], [INTERNAL_NOTE, true]]);
});
