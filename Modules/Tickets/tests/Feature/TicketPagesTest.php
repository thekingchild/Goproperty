<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Tickets\Enums\TicketSource;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Tickets\TicketService;
use Modules\Tickets\Tickets\TicketSettings;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/ticket-world.php';

/*
 * P04-01 pages: the staff list, "Raise a ticket" (building, unit and
 * tenant pickers narrowed by BuildingAccess), a ticket's page with its
 * status actions, assignment and category/priority, Settings → Maintenance
 * tickets; and the tenant portal's Repairs: "Report a problem" from the
 * homes they rent, drafts, a ticket with "Confirm it's fixed" / "Reopen"
 * while allowed. Every action goes through the pages as the browser sends
 * it (Livewire updates) and lands in TicketService.
 */

beforeEach(function () {
    ticketWorld();
});

afterEach(fn () => ticketsFreshRequest());

describe('staff pages', function () {
    it('lists the sent tickets newest first, with search and filters kept in the URL', function () {
        $older = raiseTicket($this->acme, $this->owner, $this->gatehouse, null, ['title' => 'Gate motor broken', 'category' => 'security']);
        $this->travelTo('2027-03-16 09:00:00');
        $kemi = tenantTicket($this->acme, $this->kemi, $this->lease);
        ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->owner, $older));
        draftTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Unsent draft']);

        $page = ticketsPage($this->owner)->assertOk()->assertSee('TKT-000002 · Kitchen sink leaking')->assertSee('Flat 3B · Palm Court')->assertSee('Kemi Adebayo')->assertDontSee('Unsent draft');
        expect(listedTickets($page))->toBe([$kemi->id, $older->id]);

        expect(listedTickets(ticketsPage($this->owner, '?status=open')))->toBe([$kemi->id])
            ->and(listedTickets(ticketsPage($this->owner, '?status=resolved')))->toBe([$older->id])
            ->and(listedTickets(ticketsPage($this->owner, '?q=TKT-000001')))->toBe([$older->id])
            ->and(listedTickets(ticketsPage($this->owner, '?q=adebayo')))->toBe([$kemi->id])
            ->and(listedTickets(ticketsPage($this->owner, '?q=motor')))->toBe([$older->id])
            ->and(listedTickets(ticketsPage($this->owner, '?building='.$this->oak->id)))->toBe([]);

        ticketsPage($this->owner, '?q=nothing-like-this')->assertSee('No ticket matches');
    });

    it('raises a ticket for a tenant from the form: the unit\'s tenant chosen at first, the assignee from the building\'s members', function () {
        $form = ticketsSnapshot($this->owner, '/create');
        $building = ticketsUpdate($this->owner, $form, ['propertyId' => (string) $this->palm->id])->assertOk();
        expect(LivewireHttp::html($building))->toContain('Flat 3B')->toContain('Gatehouse')->not->toContain('Unit 1')
            ->toContain('Bola Agent');

        $unit = ticketsUpdate($this->owner, LivewireHttp::next($building), ['unitId' => (string) $this->flat3b->id])->assertOk();
        expect(json_decode(LivewireHttp::next($unit), true)['data']['tenantId'])->toBe((string) $this->kemiAtAcme->id)
            ->and(LivewireHttp::html($unit))->toContain('Kemi Adebayo')->not->toContain('Tunde Bakare');

        $saved = ticketsUpdate($this->owner, LivewireHttp::next($unit), ticketInput(['assigneeId' => (string) $this->agent->id]), [['save']])->assertOk();
        $ticket = ticketsOf($this->acme)->sole();

        expect(LivewireHttp::redirect($saved))->toEndWith("/o/acme-homes/tickets/{$ticket->id}")
            ->and($ticket->status)->toBe(TicketStatus::Submitted)
            ->and($ticket->source)->toBe(TicketSource::Staff)
            ->and($ticket->tenant_profile_id)->toBe($this->kemiAtAcme->id)
            ->and($ticket->lease_id)->toBe($this->lease->id)
            ->and($ticket->assigned_to_user_id)->toBe($this->agent->id)
            ->and($ticket->raised_by_user_id)->toBe($this->owner->id);

        ticketsPage($this->owner, "/{$ticket->id}")->assertOk()->assertSee('Ticket TKT-000001 was raised.');
    });

    it('raises a ticket for a unit with no tenant, and refuses a tenant who does not rent the unit', function () {
        $form = ticketsSnapshot($this->owner, '/create?unit='.$this->gatehouse->id);
        expect(json_decode(LivewireHttp::next(ticketsUpdate($this->owner, $form)), true)['data']['propertyId'])->toBe((string) $this->palm->id);

        $refused = ticketsUpdate($this->owner, $form, ticketInput(['tenantId' => (string) $this->kemiAtAcme->id]), [['save']])->assertOk();
        expect(LivewireHttp::errors($refused))->toHaveKey('tenantId')
            ->and(ticketsOf($this->acme))->toHaveCount(0);

        ticketsUpdate($this->owner, $form, ticketInput(['title' => 'Gate motor broken']), [['save']])->assertOk();
        expect(ticketsOf($this->acme)->sole()->tenant_profile_id)->toBeNull();

        // Missing fields are the form's errors, not a ticket.
        $empty = ticketsUpdate($this->owner, ticketsSnapshot($this->owner, '/create'), ['title' => '', 'category' => ''], [['save']])->assertOk();
        expect(LivewireHttp::errors($empty))->toHaveKeys(['unitId', 'title', 'category', 'description']);
    });

    it('offers a narrowed agent only their buildings on the form, and ignores ?unit= of another building', function () {
        $form = ticketsPage($this->agent, '/create?unit='.$this->oakUnit->id)->assertOk();

        expect(htmlOf($form))->toContain('Palm Court')->not->toContain('Oak Lodge')
            ->and(json_decode(LivewireHttp::snapshot($form), true)['data']['unitId'])->toBe('');

        // The building picker is checked again on the server: another building lists no units.
        $tried = ticketsUpdate($this->agent, LivewireHttp::snapshot($form), ['propertyId' => (string) $this->oak->id])->assertOk();
        expect(LivewireHttp::html($tried))->not->toContain('Unit 1');
    });

    it('moves a ticket through its states from its page, with the buttons the state allows', function () {
        $ticket = tenantTicket($this->acme, $this->kemi, $this->lease);
        $page = ticketsPage($this->agent, "/{$ticket->id}")->assertOk()
            ->assertSee('data-test="start-review"', false)->assertSee('data-test="start-work"', false)->assertSee('data-test="resolve"', false)
            ->assertDontSee('data-test="close"', false)->assertDontSee('data-test="reopen"', false)
            ->assertSee('The tenant, in the portal');
        $snapshot = LivewireHttp::snapshot($page);

        $reviewed = ticketsUpdate($this->agent, $snapshot, [], [['startReview']])->assertOk();
        expect(LivewireHttp::html($reviewed))->toContain('The ticket is under review.')->not->toContain('data-test="start-review"');
        $working = ticketsUpdate($this->agent, LivewireHttp::next($reviewed), [], [['startWork']])->assertOk();
        $resolved = ticketsUpdate($this->agent, LivewireHttp::next($working), ['note' => 'Replaced the trap and the seal.'], [['resolve']])->assertOk();
        expect(LivewireHttp::html($resolved))->toContain('data-test="close"')->toContain('data-test="reopen"')->toContain('data-test="reopenable-until"')->toContain('Replaced the trap and the seal.');

        // A refused move shows why and changes nothing (a stale page: review after resolving).
        $stale = ticketsUpdate($this->agent, $snapshot, [], [['startReview']])->assertOk();
        expect(LivewireHttp::html($stale))->toContain('data-test="ticket-warning"')->toContain('A ticket that is resolved cannot be put under review.');

        $reopened = ticketsUpdate($this->agent, LivewireHttp::next($resolved), ['reason' => ''], [['reopen']])->assertOk();
        expect(LivewireHttp::errors($reopened))->toHaveKey('reason');
        ticketsUpdate($this->agent, LivewireHttp::next($resolved), ['reason' => 'Neighbour still sees water.'], [['reopen']])->assertOk();
        ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->agent, $ticket));
        ticketsUpdate($this->agent, ticketsSnapshot($this->agent, "/{$ticket->id}"), ['note' => 'Tenant confirmed by phone.'], [['close']])->assertOk();

        expect(array_map(fn (array $row): string => $row[1], ticketHistory($this->acme, $ticket)))->toBe(['draft', 'submitted', 'under_review', 'in_progress', 'resolved', 'reopened', 'resolved', 'closed'])
            ->and(storedTicket($this->acme, $ticket)->reopen_count)->toBe(1);

        // Closed: no action, no assignment form.
        ticketsPage($this->agent, "/{$ticket->id}")->assertOk()->assertSee('data-test="reopen"', false)->assertDontSee('data-test="save-assignee"', false)->assertDontSee('data-test="resolve"', false);
    });

    it('assigns a ticket and changes its category and priority from its page', function () {
        $ticket = raiseTicket($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
        $page = ticketsSnapshot($this->admin, "/{$ticket->id}");

        $assigned = ticketsUpdate($this->admin, $page, ['assigneeId' => (string) $this->agent->id], [['assign']])->assertOk();
        expect(LivewireHttp::html($assigned))->toContain('The ticket was assigned.');
        ticketsUpdate($this->admin, LivewireHttp::next($assigned), ['category' => 'electrical', 'priority' => 'urgent'], [['saveDetails']])->assertOk();
        ticketsUpdate($this->admin, LivewireHttp::next($assigned), ['assigneeId' => (string) $this->kemi->id], [['assign']])->assertOk();

        $stored = storedTicket($this->acme, $ticket);
        expect($stored->assigned_to_user_id)->toBe($this->agent->id)
            ->and($stored->category->value)->toBe('electrical')
            ->and($stored->priority->value)->toBe('urgent');

        ticketsPage($this->owner)->assertSee('Bola Agent')->assertSee('data-test="priority-'.$ticket->id.'"', false);
    });

    it('lets owners and administrators change the reopen window and auto-close days, audited; agents only see the sidebar link to tickets', function () {
        $page = test()->actingAs($this->admin)->get('/o/acme-homes/settings/operations/tickets')->assertOk()->assertSee('data-test="save-settings"', false);
        $snapshot = LivewireHttp::snapshot($page);
        ticketsFreshRequest();
        $this->actingAs($this->admin);
        LivewireHttp::update(test(), $snapshot, ['reopenDays' => '30', 'autoCloseDays' => '3'], [['save']])->assertOk();

        expect(inOrganization($this->acme, fn (): array => [app(TicketSettings::class)->reopenDays(), app(TicketSettings::class)->autoCloseDays()]))->toBe([30, 3])
            ->and(AuditEntry::query()->where('log_name', 'settings')->count())->toBeGreaterThanOrEqual(2);

        ticketsFreshRequest();
        $this->actingAs($this->admin);
        $refused = LivewireHttp::update(test(), $snapshot, ['reopenDays' => '91', 'autoCloseDays' => '0'], [['save']])->assertOk();
        expect(LivewireHttp::errors($refused))->toHaveKeys(['reopenDays', 'autoCloseDays']);

        ticketsFreshRequest();
        test()->actingAs($this->agent)->get('/o/acme-homes/settings/operations/tickets')->assertForbidden();
        expect(inOrganization($this->acme, fn () => app(SettingsStore::class)->get(TicketSettings::REOPEN_DAYS)))->toBe(30);

        ticketsFreshRequest();
        $sidebar = test()->actingAs($this->agent)->get('/o/acme-homes')->assertOk();
        expect(htmlOf($sidebar))->toContain('data-nav="tickets"');
    });
});

describe('tenant portal', function () {
    it('reports a problem and sends it at once, with the home chosen from the tenant\'s lease', function () {
        $form = repairsPage($this->kemi, '/new')->assertOk()->assertSee('Flat 3B · Palm Court')->assertDontSee('autofocus', false);
        $snapshot = LivewireHttp::snapshot($form);
        expect(json_decode($snapshot, true)['data']['home'])->toBe($this->acme->id.'-'.$this->lease->id);

        ticketsFreshRequest();
        $this->actingAs($this->kemi);
        $sent = LivewireHttp::update(test(), $snapshot, ticketInput(['priority' => 'urgent']), [['send']])->assertOk();
        $ticket = ticketsOf($this->acme)->sole();

        expect(LivewireHttp::redirect($sent))->toEndWith("/portal/tickets/{$ticket->id}")
            ->and($ticket->status)->toBe(TicketStatus::Submitted)
            ->and($ticket->number)->toBe('TKT-000001')
            ->and($ticket->source)->toBe(TicketSource::Portal)
            ->and($ticket->priority->value)->toBe('urgent')
            ->and($ticket->tenant_profile_id)->toBe($this->kemiAtAcme->id)
            ->and($ticket->unit_id)->toBe($this->flat3b->id)
            ->and($ticket->raised_by_user_id)->toBe($this->kemi->id);

        repairsPage($this->kemi, "/{$ticket->id}")->assertOk()->assertSee('Sent to Adeyemi Realty Ltd as TKT-000001.')->assertSee('Kitchen sink leaking');
    });

    it('keeps a draft, finishes it later and sends it, or discards it', function () {
        $snapshot = repairsSnapshot($this->kemi, '/new');
        $saved = LivewireHttp::update(test(), $snapshot, ticketInput(['description' => '']), [['saveDraft']])->assertOk();
        $draft = ticketsOf($this->acme)->sole();
        expect(LivewireHttp::redirect($saved))->toEndWith('/portal/tickets')
            ->and($draft->status)->toBe(TicketStatus::Draft)
            ->and($draft->number)->toBeNull();

        repairsPage($this->kemi)->assertOk()->assertSee('Drafts: not sent yet')->assertSee(route('portal.tickets.edit', $draft->id), false);

        // Sending needs the description.
        $edit = repairsSnapshot($this->kemi, "/{$draft->id}/edit");
        $refused = LivewireHttp::update(test(), $edit, [], [['send']])->assertOk();
        expect(LivewireHttp::errors($refused))->toHaveKey('description')
            ->and(storedTicket($this->acme, $draft)->status)->toBe(TicketStatus::Draft);

        ticketsFreshRequest();
        $this->actingAs($this->kemi);
        LivewireHttp::update(test(), $edit, ['description' => 'Water under the sink since Monday.'], [['send']])->assertOk();
        expect(storedTicket($this->acme, $draft)->status)->toBe(TicketStatus::Submitted)
            ->and(storedTicket($this->acme, $draft)->description)->toBe('Water under the sink since Monday.');

        // A sent ticket is no longer a draft to edit.
        repairsPage($this->kemi, "/{$draft->id}/edit")->assertNotFound();

        $another = draftTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Door handle loose']);
        $discarded = LivewireHttp::update(test(), repairsSnapshot($this->kemi, "/{$another->id}/edit"), [], [['discard']])->assertOk();
        expect(LivewireHttp::redirect($discarded))->toEndWith('/portal/tickets')
            ->and(storedTicket($this->acme, $another)->trashed())->toBeTrue();
        repairsPage($this->kemi)->assertDontSee('Door handle loose');
    });

    it('refuses a home the tenant does not rent, and says so when they rent none', function () {
        $snapshot = repairsSnapshot($this->kemi, '/new');
        $refused = LivewireHttp::update(test(), $snapshot, ['home' => $this->acme->id.'-'.$this->tundeLease->id] + ticketInput(), [['send']])->assertOk();
        expect(LivewireHttp::errors($refused))->toHaveKey('home')
            ->and(ticketsOf($this->acme))->toHaveCount(0);

        $nobody = User::factory()->create(['name' => 'Ngozi Eze', 'email' => 'ngozi.eze@example.com']);
        repairsPage($nobody)->assertOk()->assertSee('No repairs yet')->assertDontSee('data-test="report-problem"', false);
        repairsPage($nobody, '/new')->assertOk()->assertSee('No home to report for');
    });

    it('confirms a resolution, which closes the ticket, and offers "Reopen" until the window ends', function () {
        $ticket = tenantTicket($this->acme, $this->kemi, $this->lease);
        repairsPage($this->kemi, "/{$ticket->id}")->assertOk()->assertDontSee('data-test="confirm-fixed"', false)->assertDontSee('data-test="reopen-ticket"', false);
        ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->agent, $ticket, 'Replaced the trap.'));

        $page = repairsPage($this->kemi, "/{$ticket->id}")->assertOk()->assertSee('data-test="confirm-fixed"', false)->assertSee('data-test="reopen-ticket"', false)
            ->assertSee('Replaced the trap.')->assertSee('Bola Agent')->assertSee('You can reopen it until 29 Mar 2027, 11:00.');
        $confirmed = LivewireHttp::update(test(), LivewireHttp::snapshot($page), [], [['confirm']])->assertOk();
        expect(LivewireHttp::html($confirmed))->toContain('Thank you. The ticket is closed.')->not->toContain('data-test="confirm-fixed"')->toContain('data-test="reopen-ticket"')
            ->and(storedTicket($this->acme, $ticket)->status)->toBe(TicketStatus::Closed);

        // After the window, no "Reopen".
        $this->travelTo('2027-03-29 10:00:01');
        repairsPage($this->kemi, "/{$ticket->id}")->assertOk()->assertDontSee('data-test="reopen-ticket"', false);
        // Since the P04-03 inbox, a closed ticket is under "Finished".
        repairsPage($this->kemi)->assertOk()->assertSee('data-test="portal-tab-finished" data-count="1"', false);
        repairsPage($this->kemi, '?show=finished')->assertOk()->assertSee('Closed')->assertSee($ticket->number);
    });

    it('reopens a resolved ticket from its page with what is still wrong', function () {
        $ticket = tenantTicket($this->acme, $this->kemi, $this->lease);
        ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->agent, $ticket));
        $snapshot = repairsSnapshot($this->kemi, "/{$ticket->id}");

        $empty = LivewireHttp::update(test(), $snapshot, ['reason' => ''], [['reopen']])->assertOk();
        expect(LivewireHttp::errors($empty))->toHaveKey('reason');

        ticketsFreshRequest();
        $this->actingAs($this->kemi);
        $reopened = LivewireHttp::update(test(), $snapshot, ['reason' => 'Still dripping at night.'], [['reopen']])->assertOk();
        expect(LivewireHttp::html($reopened))->toContain('The ticket is open again.')
            ->and(storedTicket($this->acme, $ticket)->status)->toBe(TicketStatus::Reopened)
            ->and(storedTicket($this->acme, $ticket)->reopen_count)->toBe(1);

        repairsPage($this->kemi, "/{$ticket->id}")->assertSee('Still dripping at night.')->assertSee('You');
    });

    it('shows the Repairs tab on every portal page', function () {
        $home = test()->actingAs($this->kemi)->get('/portal')->assertOk();
        expect(htmlOf($home))->toContain('data-tab="repairs"')->toContain(route('portal.tickets.index'));

        $repairs = repairsPage($this->kemi)->assertOk();
        expect(htmlOf($repairs))->toMatch('/data-tab="repairs"[^>]*aria-current|aria-current="page"[^>]*data-tab="repairs"/');
    });

    it('never lets a member who is not the ticket\'s tenant use the tenant\'s actions', function () {
        joinAs($this->kemi, $this->acme, OrganizationRole::Agent);
        $tunde = tenantTicket($this->acme, $this->tunde, $this->tundeLease);
        ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->agent, $tunde));

        // Kemi is an agent here: she works Tunde's ticket in the workspace, never as its tenant in the portal.
        repairsPage($this->kemi, "/{$tunde->id}")->assertNotFound();
        expect(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->confirmResolution($this->kemi, $tunde)))->toThrow(AuthorizationException::class)
            ->and(storedTicket($this->acme, $tunde)->status)->toBe(TicketStatus::Resolved)
            ->and(ticketsOf($this->acme))->toHaveCount(1);
    });
});
