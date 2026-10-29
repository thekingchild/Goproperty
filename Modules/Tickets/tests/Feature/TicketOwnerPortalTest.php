<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Properties\OwnerPortal\OwnerLinks;
use Modules\Tickets\Messages\TicketMessages;
use Modules\Tickets\Tickets\TicketRefused;
use Modules\Tickets\Tickets\TicketService;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/message-world.php';

/*
 * P04-02: the building's owner (landlord) in /owner (ARCHITECTURE.md § 5
 * "respond to tickets if no agent assigned"; docs/plans/P04-subtasks.md
 * default 8; P04-06: only the tickets the agency shared with them): a
 * read-only list of the shared tickets of their buildings (number, title,
 * category, priority, status, dates), a ticket's page with the thread
 * (never internal notes; the tenant's name, phone and email, product owner
 * 2026-10-29) and a reply only while no agent is
 * assigned — enforced at the writer, on the locked ticket. Another
 * owner's and another organization's tickets answer 404.
 */

beforeEach(function () {
    messageWorld();
    $this->palmTicket = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi sink leaking', 'priority' => 'urgent']);
    $this->gateTicket = raiseTicket($this->acme, $this->owner, $this->gatehouse, null, ['title' => 'Gate motor broken', 'category' => 'security']);
    $this->oakTicket = raiseTicket($this->acme, $this->owner, $this->oakUnit, $this->femi, ['title' => 'Oak borehole pump down']);
    $this->palmDraft = draftTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi draft about the door']);
    $this->foreignTicket = raiseTicket($this->other, $this->otherOwner, $this->foreignUnit, $this->foreignTenant, ['title' => 'Foreign roof leaking']);
    // The owner sees only what the agency shares with them (P04-06); TicketOwnerSharingTest covers the unshared ones.
    $this->palmTicket = shareTicket($this->acme, $this->owner, $this->palmTicket);
    $this->gateTicket = shareTicket($this->acme, $this->owner, $this->gateTicket);
    $this->oakTicket = shareTicket($this->acme, $this->owner, $this->oakTicket);
});

afterEach(fn () => ticketsFreshRequest());

it('acceptance: lists the sent tickets of the owner\'s buildings in /owner, never a draft, another owner\'s or another organization\'s', function () {
    $page = ownerTicketsPage($this->ngozi)->assertOk()
        ->assertSee('TKT-000001')->assertSee('Kemi sink leaking')->assertSee('Urgent')->assertSee('Plumbing')->assertSee('Flat 3B · Palm Court')
        ->assertSee('Gate motor broken')->assertSee('Security')
        ->assertDontSee('Oak borehole pump down')->assertDontSee('Kemi draft about the door')->assertDontSee('Foreign roof leaking')
        // Names only: no tenant contact on the list.
        ->assertDontSee('+2348031112222')->assertDontSee('kemi.adebayo@example.com');

    expect(listedTickets($page))->toBe([$this->gateTicket->id, $this->palmTicket->id])
        ->and(listedTickets(ownerTicketsPage($this->femiOwner)->assertOk()))->toBe([$this->oakTicket->id]);

    // The home links to it; someone with no buildings sees an empty list.
    ticketsFreshRequest();
    $this->actingAs($this->ngozi)->get('/owner')->assertOk()->assertSee('data-test="owner-tickets-link"', false);
    ownerTicketsPage($this->kemi)->assertOk()->assertSee('No repairs yet');
});

it('acceptance: answers 404 for another owner\'s ticket, a draft, another organization\'s ticket and a missing one', function () {
    ownerTicketsPage($this->ngozi, "/{$this->palmTicket->id}")->assertOk()->assertSee('Kemi sink leaking');

    foreach ([$this->oakTicket, $this->palmDraft, $this->foreignTicket] as $ticket) {
        ownerTicketsPage($this->ngozi, "/{$ticket->id}")->assertNotFound();
        ticketsFreshRequest();
        $this->actingAs($this->ngozi)->get("/owner/tickets/{$ticket->id}/files/1")->assertNotFound();
    }
    ownerTicketsPage($this->ngozi, '/999999')->assertNotFound();
    ownerTicketsPage($this->femiOwner, "/{$this->palmTicket->id}")->assertNotFound();
    // Tenants and members are not owners of the building.
    ownerTicketsPage($this->kemi, "/{$this->palmTicket->id}")->assertNotFound();
    ownerTicketsPage($this->owner, "/{$this->palmTicket->id}")->assertNotFound();

    // An owner whose access the agency removed sees nothing any more.
    inOrganization($this->acme, fn () => app(OwnerLinks::class)->unlink($this->ngoziAtAcme, $this->ngozi, $this->acme));
    ownerTicketsPage($this->ngozi, "/{$this->palmTicket->id}")->assertNotFound();
    expect(listedTickets(ownerTicketsPage($this->ngozi)->assertOk()))->toBe([]);
});

it('shows the owner the ticket and its thread without internal notes, and the tenant\'s name, phone and email', function () {
    staffReply($this->acme, $this->agent, $this->palmTicket, 'A plumber comes tomorrow.');
    staffReply($this->acme, $this->agent, $this->palmTicket, INTERNAL_NOTE, internal: true);
    tenantReply($this->acme, $this->kemi, $this->palmTicket, 'I will be home after 9.');

    // Owners always see their tenants' phone and email (product owner 2026-10-29: the agency's switch is gone).
    $page = ownerTicketsPage($this->ngozi, "/{$this->palmTicket->id}")->assertOk()
        ->assertSee('Kemi Adebayo')->assertSee('A plumber comes tomorrow.')->assertSee('I will be home after 9.')->assertSee('Nobody at the agency yet')
        ->assertSee('+2348031112222')->assertSee('kemi.adebayo@example.com')->assertSee('data-test="owner-ticket-contact"', false)
        ->assertDontSee('STAFF-ONLY')
        ->assertSee('data-test="message-form"', false);
    expect(substr_count((string) $page->getContent(), 'data-test="thread-message"'))->toBe(2);

    // A ticket with no tenant (a shared area) shows no contact.
    ownerTicketsPage($this->ngozi, "/{$this->gateTicket->id}")->assertOk()->assertSee('No tenant (a shared area)')->assertDontSee('data-test="owner-ticket-contact"', false);
});

it('acceptance: lets the owner reply while no agent is assigned; the tenant and staff see it', function () {
    $snapshot = componentSnapshot(ownerTicketsPage($this->ngozi, "/{$this->palmTicket->id}")->assertOk(), 'owner-ticket-conversation');
    $sent = threadUpdate($this->ngozi, $snapshot, ['body' => 'I have called a plumber I trust; the agency will confirm.'], [['send']])->assertOk();

    expect(LivewireHttp::html($sent))->toContain('Message sent.')->toContain('I have called a plumber I trust')
        ->and(threadOf($this->acme, $this->palmTicket))->toBe([['owner', 'I have called a plumber I trust; the agency will confirm.', false]])
        ->and(AuditEntry::query()->where('event', 'ticket.message_posted')->sole()->causer_id)->toBe($this->ngozi->id);

    repairsPage($this->kemi, "/{$this->palmTicket->id}")->assertOk()->assertSee('I have called a plumber I trust')->assertSee('Owner');
    ticketsPage($this->agent, "/{$this->palmTicket->id}")->assertOk()->assertSee('I have called a plumber I trust');
});

it('acceptance: refuses the owner\'s reply once an agent is assigned, writing nothing, even from a page opened before', function () {
    $snapshot = componentSnapshot(ownerTicketsPage($this->ngozi, "/{$this->palmTicket->id}")->assertOk(), 'owner-ticket-conversation');
    ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $this->palmTicket, $this->agent->id));
    $audits = AuditEntry::query()->count();

    // The form opened before the assignment: refused, nothing written.
    $refused = threadUpdate($this->ngozi, $snapshot, ['body' => 'Sent after the agent took it'], [['send']]);
    expect($refused->status())->toBe(403)
        ->and(threadOf($this->acme, $this->palmTicket))->toBe([])
        ->and(AuditEntry::query()->count())->toBe($audits);

    // The writer itself refuses it (the policy, then the locked row).
    expect(fn () => ownerReply($this->acme, $this->ngozi, storedTicket($this->acme, $this->palmTicket), 'Straight to the writer'))->toThrow(AuthorizationException::class, 'An agent of the agency is handling this ticket now')
        ->and(threadOf($this->acme, $this->palmTicket))->toBe([]);

    // The page now says why and offers no form; the thread still shows.
    staffReply($this->acme, $this->agent, $this->palmTicket, 'I am on it.');
    ownerTicketsPage($this->ngozi, "/{$this->palmTicket->id}")->assertOk()->assertSee('I am on it.')->assertSee('Bola Agent')
        ->assertSee('data-test="owner-reply-assigned"', false)->assertDontSee('data-test="message-form"', false);

    // Unassigned again, the owner may reply.
    ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $this->palmTicket, null));
    ownerReply($this->acme, $this->ngozi, $this->palmTicket, 'Thanks, following up myself.');
    expect(array_column(threadOf($this->acme, $this->palmTicket), 0))->toBe(['staff', 'owner']);
});

it('re-checks the assignment on the locked ticket: an owner\'s reply that passed the page loses to an assignment made meanwhile', function () {
    // The policy saw an unassigned ticket; the row was assigned before the writer locked it.
    $stale = storedTicket($this->acme, $this->palmTicket);
    ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $this->palmTicket, $this->agent->id));
    $staleCopy = clone $stale;
    $staleCopy->assigned_to_user_id = null;

    expect(fn () => ownerReply($this->acme, $this->ngozi, $staleCopy, 'Racing the assignment'))->toThrow(TicketRefused::class, 'An agent of the agency is handling this ticket now')
        ->and(threadOf($this->acme, $this->palmTicket))->toBe([]);
});

it('keeps the owner\'s list and ticket pages read-only: every call but a refresh is refused', function () {
    ticketsFreshRequest();
    $list = LivewireHttp::snapshot(ownerTicketsPage($this->ngozi)->assertOk());
    $show = componentSnapshot(ownerTicketsPage($this->ngozi, "/{$this->palmTicket->id}")->assertOk(), 'owner-ticket-show');

    threadUpdate($this->ngozi, $list, [], [['$refresh']])->assertOk();
    threadUpdate($this->ngozi, $show, [], [['$refresh']])->assertOk();
    threadUpdate($this->ngozi, $show, [], [['render']])->assertForbidden();
    threadUpdate($this->ngozi, $list, [], [['render']])->assertForbidden();
});

it('refuses the owner\'s reply on a closed ticket, and staff\'s internal-note flag is not the owner\'s to set', function () {
    ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->freeAgent, $this->gateTicket));
    ticketsDo($this->acme, fn (TicketService $s) => $s->close($this->freeAgent, $this->gateTicket));

    ownerTicketsPage($this->ngozi, "/{$this->gateTicket->id}")->assertOk()->assertSee('data-test="thread-closed"', false)->assertDontSee('data-test="message-form"', false);
    expect(fn () => ownerReply($this->acme, $this->ngozi, $this->gateTicket, 'Late remark'))->toThrow(TicketRefused::class)
        ->and(fn () => inOrganization($this->acme, fn () => app(TicketMessages::class)->reply($this->ngozi, $this->palmTicket, 'As staff', [], true)))->toThrow(AuthorizationException::class)
        ->and(threadOf($this->acme, $this->gateTicket))->toBe([]);
});
