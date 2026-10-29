<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Modules\Properties\Models\Property;
use Modules\Tickets\Events\TicketMessagePosted;
use Modules\Tickets\Events\TicketSharedWithOwner;
use Modules\Tickets\Events\TicketStatusChanged;
use Modules\Tickets\Listeners\TellTicketParties;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Models\TicketMessage;
use Modules\Tickets\Models\TicketNotice;
use Modules\Tickets\Tickets\TicketRefused;
use Modules\Tickets\Tickets\TicketService;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/message-world.php';
require_once __DIR__.'/notices.php';
require_once __DIR__.'/../../../../tests/Feature/Exports/helpers.php';

/*
 * P04-06: the agency chooses which tickets the building's owner sees
 * (docs/plans/owner-visibility-2026-10-29.md, "Settled by the product
 * owner" 4–5 and the P04-06 defaults). Every ticket starts hidden from the
 * owner; a member holding `tickets.manage` on its building shares it (and
 * may stop), audited and shown on the staff page. Only then does the
 * owner see it in /owner (list, page, files, reply while unassigned) and
 * hear of it: once when shared, then each status change and each public
 * reply by staff or the tenant while shared — never an internal note, an
 * assignment or their own reply. Unsharing tells nobody and stops it all.
 * The tenant sees no difference.
 *
 * World (ticket-world.php + message-world.php): Palm Court belongs to
 * Ngozi Eze (linked to her /owner account), Oak Lodge to Femi Bakare. The
 * real notification pipeline runs (sync queue, `notifications`, the array
 * mailer).
 */

beforeEach(function () {
    messageWorld();
    $this->ticket = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi sink leaking']);
    $this->reply = staffReply($this->acme, $this->agent, $this->ticket, 'A plumber comes tomorrow at 10.', [ticketPhoto('plumber-visit.jpg')]);
    $this->file = messageFiles($this->acme, $this->reply)[0];
});

afterEach(fn () => ticketsFreshRequest());

/** Whether the owner portal answers 404 for the ticket's page and both file routes, for $user. */
function ownerCannotReach(User $user, Ticket $ticket, int $mediaId): void
{
    ownerTicketsPage($user, "/{$ticket->id}")->assertNotFound();

    foreach (['', '/thumb'] as $suffix) {
        ticketsFreshRequest();
        test()->actingAs($user)->get("/owner/tickets/{$ticket->id}/files/{$mediaId}{$suffix}")->assertNotFound();
    }
}

/** The status (404, 403, 200) each owner ability answers for $user on the ticket. */
function ownerAbilities(User $user, Ticket $ticket): array
{
    return inOrganization(test()->acme, fn (): array => collect(['viewAsOwner', 'replyAsOwner', 'downloadAttachmentAsOwner'])
        ->mapWithKeys(fn (string $ability): array => [$ability => Gate::forUser($user)->inspect($ability, Ticket::query()->findOrFail($ticket->id))->status() ?? 200])
        ->all());
}

it('acceptance: keeps an unshared ticket out of /owner: not listed, its page and files 404, and no reply', function () {
    expect(storedTicket($this->acme, $this->ticket)->sharedWithOwner())->toBeFalse()
        ->and(listedTickets(ownerTicketsPage($this->ngozi)->assertOk()->assertDontSee('Kemi sink leaking')->assertSee('No repairs shared with you here yet')))->toBe([]);
    ownerCannotReach($this->ngozi, $this->ticket, $this->file->id);
    expect(ownerAbilities($this->ngozi, $this->ticket))->toBe(['viewAsOwner' => 404, 'replyAsOwner' => 404, 'downloadAttachmentAsOwner' => 404]);

    // The writer refuses the owner's reply as not found, writing nothing.
    expect(fn () => ownerReply($this->acme, $this->ngozi, $this->ticket, 'Can I see this?'))->toThrow(AuthorizationException::class)
        ->and(array_column(threadOf($this->acme, $this->ticket), 0))->toBe(['staff']);

    // The tenant and staff see it as before.
    repairsPage($this->kemi, "/{$this->ticket->id}")->assertOk()->assertSee('Kemi sink leaking')->assertDontSee('Shared with');
    ticketsPage($this->agent, "/{$this->ticket->id}")->assertOk()->assertSee('data-test="ticket-not-shared"', false)->assertSee('Ngozi Eze does not see this ticket');
});

it('acceptance: sharing shows the ticket to the owner and tells the owner\'s linked user once; a retried job tells nobody twice', function () {
    $told = ticketToldBy(fn () => shareTicket($this->acme, $this->agent, $this->ticket));

    // Only the owner, in the app and by email; never the tenant or staff.
    expect($told)->toEqual([TN_NGOZI => ['app:ticket.shared', 'mail']]);

    $stored = storedTicket($this->acme, $this->ticket);
    expect($stored->shared_with_owner_at?->toDateTimeString())->toBe('2027-03-15 10:00:00')
        ->and($stored->shared_with_owner_by_user_id)->toBe($this->agent->id);

    $audit = AuditEntry::query()->where('event', 'ticket.shared_with_owner')->sole();
    expect($audit->causer_id)->toBe($this->agent->id)
        ->and($audit->subject_id)->toBe($this->ticket->id)
        ->and($audit->properties['owner_profile_id'] ?? null)->toBe($this->ngoziAtAcme->id);

    // The owner now finds it: listed, its page, its files, and a reply while nobody is assigned.
    expect(listedTickets(ownerTicketsPage($this->ngozi)->assertOk()))->toBe([$this->ticket->id]);
    ownerTicketsPage($this->ngozi, "/{$this->ticket->id}")->assertOk()->assertSee('A plumber comes tomorrow at 10.')->assertSee('data-test="message-form"', false);
    ticketsFreshRequest();
    $this->actingAs($this->ngozi)->get("/owner/tickets/{$this->ticket->id}/files/{$this->file->id}")->assertRedirect();
    expect(ownerAbilities($this->ngozi, $this->ticket))->toBe(['viewAsOwner' => 200, 'replyAsOwner' => 200, 'downloadAttachmentAsOwner' => 200]);

    // The email and the in-app notice link to the owner portal, never the workspace.
    $mail = collect(app('mailer')->getSymfonyTransport()->messages())->sole()->getOriginalMessage();
    $inApp = json_decode((string) DB::table('notifications')->where('notifiable_id', $this->ngozi->id)->sole()->data, true);
    expect($mail->getSubject())->toBe('Adeyemi Realty Ltd shared a repair with you: TKT-000001 at Palm Court')
        ->and($mail->getHtmlBody())->toContain('Kemi sink leaking')->toContain(route('owner.tickets.show', $this->ticket->id))->not->toContain('/o/acme-homes')
        ->and($inApp['url'])->toBe(route('owner.tickets.show', $this->ticket->id))
        ->and($inApp['side'])->toBe('owner');

    // The listener retried or replayed for the same share: nobody is told again.
    $notice = inOrganization($this->acme, fn () => TicketNotice::query()->where('event', 'ticket.shared')->sole());
    $event = new TicketSharedWithOwner($this->acme->id, $this->ticket->id, 'TKT-000001', $this->agent->id, substr($notice->key, strlen('shared:')));
    expect(ticketToldBy(function () use ($event) {
        app(TellTicketParties::class)->handle($event);
        app(TellTicketParties::class)->handle(unserialize(serialize($event)));
    }))->toBe([])
        ->and(inOrganization($this->acme, fn () => TicketNotice::query()->where('ticket_id', $this->ticket->id)->where('side', 'owner')->count()))->toBe(1);

    // Sharing a shared ticket again changes nothing and tells nobody.
    expect(ticketToldBy(fn () => shareTicket($this->acme, $this->owner, $this->ticket)))->toBe([])
        ->and(storedTicket($this->acme, $this->ticket)->shared_with_owner_by_user_id)->toBe($this->agent->id)
        ->and(AuditEntry::query()->where('event', 'ticket.shared_with_owner')->count())->toBe(1);
});

it('acceptance: tells the owner of a shared ticket once per status change and public reply, never of a note, an assignment or their own reply', function () {
    shareTicket($this->acme, $this->owner, $this->ticket);
    $ticket = fn (): Ticket => storedTicket($this->acme, $this->ticket);

    // The owner's own reply (nobody assigned): the staff side, not the owner, not the tenant.
    expect(ticketToldBy(fn () => ownerReply($this->acme, $this->ngozi, $ticket(), 'I can send my own plumber.')))
        ->toEqual([TN_ADA => ['app:ticket.replied', 'mail'], TN_SEYI => ['app:ticket.replied', 'mail']]);

    // The tenant's reply and a member's reply: the owner once each.
    expect(ticketToldBy(fn () => tenantReply($this->acme, $this->kemi, $ticket(), 'I will be home after 9.')))
        ->toEqual([TN_ADA => ['app:ticket.replied', 'mail'], TN_NGOZI => ['app:ticket.replied', 'mail'], TN_SEYI => ['app:ticket.replied', 'mail']])
        ->and(ticketToldBy(fn () => staffReply($this->acme, $this->admin, $ticket(), 'Thank you, the plumber has your number.')))
        ->toEqual([TN_ADA => ['app:ticket.replied', 'mail'], TN_KEMI => ['app:ticket.replied', 'mail'], TN_NGOZI => ['app:ticket.replied', 'mail']]);

    // An internal note and an assignment: never the owner.
    expect(ticketToldBy(fn () => staffReply($this->acme, $this->admin, $ticket(), INTERNAL_NOTE, internal: true)))
        ->toEqual([TN_ADA => ['app:ticket.note_added', 'mail']])
        ->and(ticketToldBy(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $ticket(), $this->agent->id))))
        ->toEqual([TN_BOLA => ['app:ticket.assigned', 'mail']]);

    // Each status change: the owner once, with the tenant and the staff side.
    expect(ticketToldBy(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->startWork($this->agent, $ticket()))))
        ->toEqual([TN_KEMI => ['app:ticket.in_progress', 'mail'], TN_NGOZI => ['app:ticket.in_progress', 'mail']]);

    app('mailer')->getSymfonyTransport()->flush();
    expect(ticketToldBy(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->agent, $ticket(), 'Washer replaced.'))))
        ->toEqual([TN_KEMI => ['app:ticket.resolved', 'mail'], TN_NGOZI => ['app:ticket.resolved', 'mail']]);
    $ownerMail = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($message) => $message->getOriginalMessage())
        ->first(fn ($message): bool => $message->getTo()[0]->getAddress() === TN_NGOZI);
    expect($ownerMail->getSubject())->toBe('TKT-000001 at Palm Court is fixed')
        ->and($ownerMail->getHtmlBody())->toContain('Adeyemi Realty Ltd says the repair')->toContain('Washer replaced.')
        ->toContain(route('owner.tickets.show', $this->ticket->id))->not->toContain('/o/acme-homes');

    expect(ticketToldBy(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->confirmResolution($this->kemi, $ticket()))))
        ->toEqual([TN_BOLA => ['app:ticket.closed', 'mail'], TN_NGOZI => ['app:ticket.closed', 'mail']]);

    // A replayed status event or message event tells the owner nothing more (each notice keyed, P04-03).
    $ownerNotices = fn (): int => inOrganization($this->acme, fn () => TicketNotice::query()->where('ticket_id', $this->ticket->id)->where('side', 'owner')->count());
    $before = $ownerNotices();
    $statusKey = inOrganization($this->acme, fn () => TicketNotice::query()->where('side', 'owner')->where('event', 'ticket.closed')->sole()->key);
    $replayedMove = new TicketStatusChanged($this->acme->id, $this->ticket->id, 'TKT-000001', 'resolved', 'closed', TicketStatusChanged::ACTOR_TENANT, $this->kemi->id, $this->palm->id, $this->flat3b->id, $this->kemiAtAcme->id, $this->agent->id, null, substr($statusKey, strlen('status:')));
    $tenantMessage = inOrganization($this->acme, fn () => TicketMessage::query()->where('ticket_id', $this->ticket->id)->where('author_kind', 'tenant')->sole());
    $replayedMessage = new TicketMessagePosted($this->acme->id, $this->ticket->id, 'TKT-000001', $tenantMessage->id, 'tenant', $this->kemi->id, false, $this->kemiAtAcme->id, null, $this->palm->id, 0);

    expect(ticketToldBy(function () use ($replayedMove, $replayedMessage) {
        app(TellTicketParties::class)->handle($replayedMove);
        app(TellTicketParties::class)->handle(unserialize(serialize($replayedMessage)));
    }))->toBe([])
        ->and($ownerNotices())->toBe($before)
        ->and($before)->toBe(6);
});

it('acceptance: tells the owner nothing once the ticket is no longer shared, and nobody of the unsharing', function () {
    shareTicket($this->acme, $this->owner, $this->ticket);
    $page = ownerTicketsPage($this->ngozi, "/{$this->ticket->id}")->assertOk();
    $snapshot = componentSnapshot($page, 'owner-ticket-conversation');

    // Stopping the share tells nobody, the owner included.
    expect(ticketToldBy(fn () => unshareTicket($this->acme, $this->admin, $this->ticket)))->toBe([]);

    $stored = storedTicket($this->acme, $this->ticket);
    $audit = AuditEntry::query()->where('event', 'ticket.unshared_with_owner')->sole();
    expect($stored->shared_with_owner_at)->toBeNull()
        ->and($stored->shared_with_owner_by_user_id)->toBeNull()
        ->and($audit->causer_id)->toBe($this->admin->id)
        ->and($audit->properties['shared_by_user_id'] ?? null)->toBe($this->owner->id);

    // Hidden again: not listed, page and files 404, no reply — even from the page opened before.
    expect(listedTickets(ownerTicketsPage($this->ngozi)->assertOk()))->toBe([]);
    ownerCannotReach($this->ngozi, $this->ticket, $this->file->id);
    expect(threadUpdate($this->ngozi, $snapshot, ['body' => 'Hello?'], [['send']])->status())->toBe(404)
        ->and(array_column(threadOf($this->acme, $this->ticket), 0))->toBe(['staff']);

    // A reply that passed the policy on a stale copy loses to the unsharing on the locked row.
    $stale = clone $stored;
    $stale->shared_with_owner_at = now()->toImmutable();
    expect(fn () => ownerReply($this->acme, $this->ngozi, $stale, 'Racing the unsharing'))->toThrow(TicketRefused::class, 'no longer shares this repair')
        ->and(array_column(threadOf($this->acme, $this->ticket), 0))->toBe(['staff']);

    // Status changes and public replies no longer reach the owner.
    expect(ticketToldBy(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->startWork($this->freeAgent, storedTicket($this->acme, $this->ticket)))))->not->toHaveKey(TN_NGOZI)
        ->and(ticketToldBy(fn () => tenantReply($this->acme, $this->kemi, $this->ticket, 'Any news?')))->not->toHaveKey(TN_NGOZI)
        ->and(ticketToldBy(fn () => staffReply($this->acme, $this->admin, $this->ticket, 'Tomorrow.')))->not->toHaveKey(TN_NGOZI)
        ->and(DB::table('notifications')->where('notifiable_id', $this->ngozi->id)->count())->toBe(0);

    // Shared again: a new share, so the owner is told once more and sees it again.
    expect(ticketToldBy(fn () => shareTicket($this->acme, $this->owner, $this->ticket)))->toEqual([TN_NGOZI => ['app:ticket.shared', 'mail']])
        ->and(listedTickets(ownerTicketsPage($this->ngozi)->assertOk()))->toBe([$this->ticket->id]);
});

it('acceptance: lets only tickets.manage members who work on the building share or stop sharing (refusals and cross-organization probes)', function () {
    $oakAgent = joinAs(User::factory()->create(['name' => 'Oak Agent', 'email' => 'oak.agent@example.com']), $this->acme, OrganizationRole::Agent);
    assignIn($this->acme, $oakAgent, $this->oak);
    $audits = AuditEntry::query()->count();

    // Refused, writing nothing: an agent of another building (404), the tenant, the building's owner, another organization's owner.
    $refusals = [];
    foreach (['agent of another building' => $oakAgent, 'tenant' => $this->kemi, 'property owner' => $this->ngozi, 'another organization\'s owner' => $this->otherOwner] as $who => $user) {
        foreach (['shareWithOwner', 'stopSharingWithOwner'] as $method) {
            try {
                ticketsDo($this->acme, fn (TicketService $s) => $s->{$method}($user, $this->ticket));
                $refusals[$who][] = 'allowed';
            } catch (AuthorizationException $refused) {
                $refusals[$who][] = $refused->status() ?? 403;
            }
        }
    }
    expect($refusals)->toBe([
        'agent of another building' => [404, 404],
        'tenant' => [403, 403],
        'property owner' => [403, 403],
        'another organization\'s owner' => [403, 403],
    ]);

    // Another organization's member, in their own organization, with this ticket: never.
    expect(inOrganization($this->other, fn () => Gate::forUser($this->otherOwner)->allows('shareWithOwner', $this->ticket)))->toBeFalse()
        ->and(fn () => inOrganization($this->other, fn () => app(TicketService::class)->shareWithOwner($this->otherOwner, $this->ticket)))->toThrow(AuthorizationException::class);
    ticketsPage($this->otherOwner, "/{$this->ticket->id}", 'other-realty')->assertNotFound();
    ticketsPage($oakAgent, "/{$this->ticket->id}")->assertNotFound();

    // The staff component called with another user's session: refused, nothing written.
    $snapshot = ticketsSnapshot($this->agent, "/{$this->ticket->id}");
    foreach ([$this->kemi, $this->ngozi, $oakAgent, $this->otherOwner] as $user) {
        expect(ticketsUpdate($user, $snapshot, [], [['shareWithOwner']])->status())->toBeIn([403, 404]);
    }

    expect(storedTicket($this->acme, $this->ticket)->sharedWithOwner())->toBeFalse()
        ->and(AuditEntry::query()->count())->toBe($audits)
        ->and(DB::table('notifications')->where('notifiable_id', $this->ngozi->id)->count())->toBe(0);

    // Allowed: the owner, an administrator, the building's agent and an agent of every building.
    foreach ([$this->owner, $this->admin, $this->agent, $this->freeAgent] as $member) {
        ticketsDo($this->acme, fn (TicketService $s) => $s->shareWithOwner($member, $this->ticket));
        expect(storedTicket($this->acme, $this->ticket)->shared_with_owner_by_user_id)->toBe($member->id);
        ticketsDo($this->acme, fn (TicketService $s) => $s->stopSharingWithOwner($member, $this->ticket));
        expect(storedTicket($this->acme, $this->ticket)->sharedWithOwner())->toBeFalse();
    }

    // A suspended organization is read-only: no sharing (OrganizationWriteGuard).
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();
    expect(fn () => shareTicket($this->acme, $this->owner, $this->ticket))->toThrow(AuthorizationException::class)
        ->and(storedTicket($this->acme, $this->ticket)->sharedWithOwner())->toBeFalse();
});

it('acceptance: leaves every existing ticket unshared after the migration', function () {
    // The new columns are nullable with no default: a row written without them, as every ticket before the migration, is hidden.
    // (MariaDB reports a nullable column's missing default as the string NULL, MySQL as null.)
    $columns = collect(Schema::getColumns('tickets'))->keyBy('name');
    expect($columns['shared_with_owner_at']['nullable'])->toBeTrue()
        ->and($columns['shared_with_owner_at']['default'])->toBeIn([null, 'NULL'])
        ->and($columns['shared_with_owner_by_user_id']['nullable'])->toBeTrue()
        ->and($columns['shared_with_owner_by_user_id']['default'])->toBeIn([null, 'NULL']);

    $legacyId = DB::table('tickets')->insertGetId([
        'organization_id' => $this->acme->id, 'number' => 'TKT-000900', 'property_id' => $this->palm->id, 'unit_id' => $this->flat3b->id,
        'lease_id' => $this->lease->id, 'tenant_profile_id' => $this->kemiAtAcme->id, 'source' => 'portal', 'raised_by_user_id' => $this->kemi->id,
        'category' => 'plumbing', 'priority' => 'high', 'status' => 'in_progress', 'title' => 'Legacy leaking tap', 'description' => 'From before P04-06.',
        'submitted_at' => now(), 'reopen_count' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $legacy = inOrganization($this->acme, fn (): Ticket => Ticket::query()->findOrFail($legacyId));

    expect($legacy->shared_with_owner_at)->toBeNull()
        ->and($legacy->shared_with_owner_by_user_id)->toBeNull()
        ->and($legacy->sharedWithOwner())->toBeFalse()
        ->and(listedTickets(ownerTicketsPage($this->ngozi)->assertOk()))->toBe([]);
    ownerTicketsPage($this->ngozi, "/{$legacyId}")->assertNotFound();

    // New tickets start hidden too, whoever raises them.
    $staffTicket = raiseTicket($this->acme, $this->owner, $this->gatehouse, null, ['title' => 'Gate motor broken']);
    expect(ticketsOf($this->acme)->map(fn (Ticket $ticket): bool => $ticket->sharedWithOwner())->unique()->all())->toBe([false])
        ->and($staffTicket->sharedWithOwner())->toBeFalse();
});

it('shows staff whether the ticket is shared and by whom, offers the action, and says so when the building has no owner', function () {
    // Not shared: the owner's name and the button.
    ticketsPage($this->agent, "/{$this->ticket->id}")->assertOk()
        ->assertSee('data-test="ticket-not-shared"', false)->assertSee('data-test="share-with-owner"', false)->assertDontSee('data-test="stop-sharing-with-owner"', false);

    // Shared from the page.
    $shared = ticketsUpdate($this->agent, ticketsSnapshot($this->agent, "/{$this->ticket->id}"), [], [['shareWithOwner']])->assertOk();
    expect(LivewireHttp::html($shared))->toContain('The ticket is shared with the building&#039;s owner.')
        ->toContain('Shared with the owner by Bola Agent on 15 Mar 2027.')->toContain('data-test="stop-sharing-with-owner"');
    ticketsPage($this->owner, "/{$this->ticket->id}")->assertOk()->assertSee('Shared with the owner by Bola Agent on 15 Mar 2027.');

    // Stopped from the page by another member.
    $stopped = ticketsUpdate($this->admin, ticketsSnapshot($this->admin, "/{$this->ticket->id}"), [], [['stopSharingWithOwner']])->assertOk();
    expect(LivewireHttp::html($stopped))->toContain('no longer sees this ticket')->toContain('data-test="share-with-owner"')
        ->and(storedTicket($this->acme, $this->ticket)->sharedWithOwner())->toBeFalse();

    // A building with no owner on record: said so, no button, and the service refuses.
    $cedar = buildingIn($this->acme, ['name' => 'Cedar House', 'slug' => 'cedar-house']);
    $cedarTicket = raiseTicket($this->acme, $this->owner, unitIn($this->acme, $cedar, ['unit_label' => 'Unit A']), null, ['title' => 'Roof leaking']);
    ticketsPage($this->owner, "/{$cedarTicket->id}")->assertOk()
        ->assertSee('data-test="ticket-no-owner"', false)->assertSee('This building has no owner on record')->assertDontSee('data-test="share-with-owner"', false);
    expect(fn () => shareTicket($this->acme, $this->owner, $cedarTicket))->toThrow(TicketRefused::class, 'no owner on record')
        ->and(storedTicket($this->acme, $cedarTicket)->sharedWithOwner())->toBeFalse();

    // The tenant's pages never mention it.
    shareTicket($this->acme, $this->owner, $this->ticket);
    repairsPage($this->kemi, "/{$this->ticket->id}")->assertOk()->assertDontSee('Shared with')->assertDontSee('owner-sharing', false);
    repairsPage($this->kemi)->assertOk()->assertDontSee('Shared with');
});

it('lets an owner without a portal account be shared with: nobody is told, and they see it once they join', function () {
    $cedar = buildingIn($this->acme, ['name' => 'Cedar House', 'slug' => 'cedar-house']);
    $tola = User::factory()->create(['name' => 'Tola Ade', 'email' => 'tola.ade@example.com', 'phone' => '+2348057776666']);
    $tolaAtAcme = ownerIn($this->acme, ['type' => 'person', 'first_name' => 'Tola', 'last_name' => 'Ade', 'email' => 'tola.ade@example.com', 'phone' => '+2348057776666']);
    inOrganization($this->acme, fn () => Property::query()->whereKey($cedar->id)->firstOrFail()->forceFill(['owner_profile_id' => $tolaAtAcme->id])->save());
    $cedarTicket = raiseTicket($this->acme, $this->owner, unitIn($this->acme, $cedar, ['unit_label' => 'Unit A']), null, ['title' => 'Roof leaking']);

    expect(ticketToldBy(fn () => shareTicket($this->acme, $this->owner, $cedarTicket)))->toBe([]);
    ticketsPage($this->owner, "/{$cedarTicket->id}")->assertOk()->assertSee('data-test="ticket-owner-no-portal"', false);

    // Linked later: the shared ticket is there, and later news reaches them.
    linkOwner($this->acme, $tolaAtAcme, $tola);
    expect(listedTickets(ownerTicketsPage($tola)->assertOk()))->toBe([$cedarTicket->id])
        ->and(ticketToldBy(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->startReview($this->owner, storedTicket($this->acme, $cedarTicket)))))
        ->toEqual(['seyi.admin@example.com' => ['app:ticket.under_review', 'mail'], 'tola.ade@example.com' => ['app:ticket.under_review', 'mail']]);
});

it('marks shared tickets on the staff list, filters by them and exports the column', function () {
    $gate = raiseTicket($this->acme, $this->owner, $this->gatehouse, null, ['title' => 'Gate motor broken']);
    shareTicket($this->acme, $this->owner, $this->ticket);

    $page = ticketsPage($this->owner)->assertOk();
    expect((string) $page->getContent())->toContain('data-test="shared-'.$this->ticket->id.'"')->not->toContain('data-test="shared-'.$gate->id.'"')
        ->toContain('data-test="owner-filter"')
        ->and(listedTickets(ticketsPage($this->owner, '?owner=shared')->assertOk()))->toBe([$this->ticket->id])
        ->and(listedTickets(ticketsPage($this->owner, '?owner=hidden')->assertOk()))->toBe([$gate->id])
        ->and(listedTickets(ticketsPage($this->owner, '?owner=nonsense')->assertOk()))->toBe([$gate->id, $this->ticket->id]);

    $rows = exportRows($this->owner, '/tickets', 'tickets');
    expect($rows[0])->toContain('Shared with owner on')
        ->and(array_combine(exportColumn($rows, 'Number'), exportColumn($rows, 'Shared with owner on')))->toBe(['TKT-000002' => '', 'TKT-000001' => '2027-03-15 11:00'])
        ->and(exportColumn(exportRows($this->owner, '/tickets?owner=shared', 'tickets'), 'Number'))->toBe(['TKT-000001']);
});
