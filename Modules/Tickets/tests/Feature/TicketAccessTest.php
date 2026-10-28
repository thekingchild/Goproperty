<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Branding\BrandColours;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Tickets\Contracts\TicketService as TicketServiceContract;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Lists\TicketList;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Tickets\Assignees;
use Modules\Tickets\Tickets\TicketService;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/ticket-world.php';

/*
 * P04-01 acceptance "visibility" (ARCHITECTURE.md § 5; TicketPolicy): a
 * tenant sees only their own tickets (each agency's in its own colours);
 * an agent assigned to buildings only those buildings' tickets, and
 * assigns only to members who work there; owners and administrators see
 * all; the wrong role is refused; another organization's ticket answers
 * 404; a tenant's draft is nobody else's. Suspended organizations are
 * read-only for members and tenants alike; impersonating staff change
 * nothing in a read-only session and work tickets in write mode, audited
 * with both identities (no ticket permission is blocked: tickets carry no
 * money, credentials or contact details).
 */

beforeEach(function () {
    ticketWorld();
    $this->kemiTicket = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi sink leaking']);
    $this->tundeTicket = tenantTicket($this->acme, $this->tunde, $this->tundeLease, ['title' => 'Tunde socket sparks', 'category' => 'electrical']);
    $this->gateTicket = raiseTicket($this->acme, $this->owner, $this->gatehouse, null, ['title' => 'Gate motor broken', 'category' => 'security']);
    $this->oakTicket = raiseTicket($this->acme, $this->owner, $this->oakUnit, $this->femi, ['title' => 'Oak borehole pump down']);
    $this->kemiDraft = draftTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi draft about the door']);
    $this->foreignTicket = raiseTicket($this->other, $this->otherOwner, $this->foreignUnit, $this->foreignTenant, ['title' => 'Foreign roof leaking']);
});

afterEach(fn () => ticketsFreshRequest());

/** @return array<string, bool> ability => allowed, as TicketPolicy answers inside acme */
function ticketAbilitiesOf(User $user, Ticket $ticket): array
{
    return inOrganization(test()->acme, fn (): array => [
        'viewAny' => Gate::forUser($user)->allows('viewAny', Ticket::class),
        'export' => Gate::forUser($user)->allows('export', Ticket::class),
        'view' => Gate::forUser($user)->allows('view', $ticket),
        'create' => Gate::forUser($user)->allows('create', Ticket::class),
        'changeStatus' => Gate::forUser($user)->allows('changeStatus', $ticket),
        'update' => Gate::forUser($user)->allows('update', $ticket),
        'delete' => Gate::forUser($user)->allows('delete', $ticket),
        'forceDelete' => Gate::forUser($user)->allows('forceDelete', $ticket),
        'viewAsTenant' => Gate::forUser($user)->allows('viewAsTenant', $ticket),
        'createAsTenant' => Gate::forUser($user)->allows('createAsTenant', Ticket::class),
    ]);
}

it('acceptance: lets owners, administrators and agents work tickets, and refuses property owners, tenants and strangers in the workspace', function (string $who, array $allowed) {
    $user = match ($who) {
        'org-owner' => $this->owner,
        'org-admin' => $this->admin,
        'agent (narrowed, own building)' => $this->agent,
        'agent (not narrowed)' => $this->freeAgent,
        'property-owner' => joinAs(User::factory()->create(), $this->acme, OrganizationRole::PropertyOwner),
        'tenant (another tenant\'s ticket)' => $this->tunde,
        'another organization\'s owner' => $this->otherOwner,
    };
    $abilities = ['viewAny', 'export', 'view', 'create', 'changeStatus', 'update', 'delete', 'forceDelete', 'viewAsTenant', 'createAsTenant'];

    expect(ticketAbilitiesOf($user, $this->kemiTicket))
        ->toBe(array_combine($abilities, array_map(fn (string $ability): bool => in_array($ability, $allowed, true), $abilities)));
})->with([
    'org-owner' => ['org-owner', ['viewAny', 'export', 'view', 'create', 'changeStatus', 'update']],
    'org-admin' => ['org-admin', ['viewAny', 'export', 'view', 'create', 'changeStatus', 'update']],
    'agent (narrowed, own building)' => ['agent (narrowed, own building)', ['viewAny', 'export', 'view', 'create', 'changeStatus', 'update']],
    'agent (not narrowed)' => ['agent (not narrowed)', ['viewAny', 'export', 'view', 'create', 'changeStatus', 'update']],
    'property-owner' => ['property-owner', []],
    // Tunde rents here (he may raise his own), but Kemi's ticket is not his.
    'tenant (another tenant\'s ticket)' => ['tenant (another tenant\'s ticket)', ['createAsTenant']],
    'another organization\'s owner' => ['another organization\'s owner', []],
]);

it('acceptance: refuses the wrong role at the workspace pages, and another organization\'s ticket answers 404', function () {
    // Tenants and property owners never open the workspace.
    ticketsPage($this->kemi)->assertNotFound();
    ticketsPage($this->kemi, "/{$this->kemiTicket->id}")->assertNotFound();
    ticketsPage(joinAs(User::factory()->create(), $this->acme, OrganizationRole::PropertyOwner))->assertNotFound();

    // Another organization's member: 404 at our slug, and our ticket's id at theirs.
    ticketsPage($this->otherOwner)->assertNotFound();
    ticketsPage($this->otherOwner, "/{$this->kemiTicket->id}", 'other-realty')->assertNotFound();
    ticketsPage($this->owner, "/{$this->foreignTicket->id}")->assertNotFound();
    expect(listedTickets(ticketsPage($this->otherOwner, '', 'other-realty')->assertOk()))->toBe([$this->foreignTicket->id])
        ->and(inOrganization($this->other, fn () => app(TicketServiceContract::class)->find($this->kemiTicket->id)))->toBeNull()
        ->and(inOrganization($this->acme, fn () => app(TicketServiceContract::class)->find($this->foreignTicket->id)))->toBeNull()
        ->and(fn () => ticketsDo($this->other, fn (TicketService $s) => $s->startReview($this->otherOwner, $this->kemiTicket)))->toThrow(AuthorizationException::class);

    // A unit of another organization cannot get a ticket here.
    expect(fn () => raiseTicket($this->acme, $this->owner, $this->foreignUnit))->toThrow(ValidationException::class, 'Choose one of your units.');
});

it('acceptance: keeps a tenant\'s draft from everyone else, staff included', function () {
    ticketsPage($this->owner, "/{$this->kemiDraft->id}")->assertNotFound();
    expect(listedTickets(ticketsPage($this->owner)->assertOk()))->not->toContain($this->kemiDraft->id)
        ->and(inOrganization($this->acme, fn () => app(TicketServiceContract::class)->find($this->kemiDraft->id)))->toBeNull()
        ->and(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $this->kemiDraft, $this->agent->id)))->toThrow(AuthorizationException::class);

    repairsPage($this->tunde, "/{$this->kemiDraft->id}")->assertNotFound();
    repairsPage($this->tunde, "/{$this->kemiDraft->id}/edit")->assertNotFound();
    repairsPage($this->kemi, "/{$this->kemiDraft->id}/edit")->assertOk()->assertSee('Kemi draft about the door');
});

it('acceptance: shows an agent assigned to buildings only those buildings\' tickets, everywhere', function () {
    // Bola works on Palm Court; Chika (no assignment) and the owner see every building.
    expect(listedTickets(ticketsPage($this->agent)->assertOk()))->toEqualCanonicalizing([$this->kemiTicket->id, $this->tundeTicket->id, $this->gateTicket->id])
        ->and(listedTickets(ticketsPage($this->freeAgent)->assertOk()))->toEqualCanonicalizing([$this->kemiTicket->id, $this->tundeTicket->id, $this->gateTicket->id, $this->oakTicket->id])
        ->and(listedTickets(ticketsPage($this->admin)->assertOk()))->toHaveCount(4);

    ticketsPage($this->agent, "/{$this->oakTicket->id}")->assertNotFound();
    ticketsPage($this->agent, "/{$this->kemiTicket->id}")->assertOk()->assertSee('Kemi sink leaking');

    // Every action on another building's ticket answers 404, and nothing changes.
    foreach (['startReview', 'startWork'] as $move) {
        expect(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->{$move}($this->agent, $this->oakTicket)))->toThrow(AuthorizationException::class);
    }

    expect(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->agent, $this->oakTicket, $this->agent->id)))->toThrow(AuthorizationException::class)
        ->and(fn () => raiseTicket($this->acme, $this->agent, $this->oakUnit, $this->femi))->toThrow(AuthorizationException::class)
        ->and(storedTicket($this->acme, $this->oakTicket)->status)->toBe(TicketStatus::Submitted)
        ->and(ticketsOf($this->acme))->toHaveCount(5);

    // The export, the contract and the inbox count follow the same narrowing.
    expect(inOrganization($this->acme, fn () => app(TicketList::class)->count($this->agent, [])))->toBe(3)
        ->and(inOrganization($this->acme, fn () => app(TicketList::class)->count($this->owner, [])))->toBe(4)
        ->and(inOrganization($this->acme, fn () => app(TicketServiceContract::class)->openCount($this->agent)))->toBe(3)
        ->and(inOrganization($this->acme, fn () => app(TicketServiceContract::class)->openCount()))->toBe(4)
        ->and(inOrganization($this->acme, fn () => app(TicketServiceContract::class)->find($this->oakTicket->id, $this->agent)))->toBeNull()
        ->and(inOrganization($this->acme, fn () => app(TicketServiceContract::class)->find($this->oakTicket->id, $this->owner)?->number))->toBe('TKT-000004')
        ->and(inOrganization($this->acme, fn () => app(TicketServiceContract::class)->forLease($this->lease->id, $this->agent)))->toHaveCount(1);
});

it('acceptance: assigns a ticket only to members who work on its building', function () {
    $palm = inOrganization($this->acme, fn (): array => app(Assignees::class)->for($this->palm->id));
    $oak = inOrganization($this->acme, fn (): array => app(Assignees::class)->for($this->oak->id));

    expect($palm)->toBe([$this->owner->id => 'Ada Owner', $this->agent->id => 'Bola Agent', $this->freeAgent->id => 'Chika Agent', $this->admin->id => 'Seyi Admin'])
        ->and($oak)->toBe([$this->owner->id => 'Ada Owner', $this->freeAgent->id => 'Chika Agent', $this->admin->id => 'Seyi Admin']);

    // The page offers the same members; the writer refuses anyone else.
    $page = ticketsPage($this->owner, "/{$this->oakTicket->id}")->assertOk();
    expect(htmlOf($page))->toContain('Chika Agent')->not->toContain('<option value="'.$this->agent->id.'"');
    expect(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $this->oakTicket, $this->agent->id)))->toThrow(ValidationException::class)
        ->and(fn () => raiseTicket($this->acme, $this->owner, $this->oakUnit, null, ['assigneeId' => $this->agent->id]))->toThrow(ValidationException::class, 'Choose a member who works on Oak Lodge.');

    // Bola may take a Palm Court ticket himself.
    ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->agent, $this->kemiTicket, $this->agent->id));
    expect(storedTicket($this->acme, $this->kemiTicket)->assigned_to_user_id)->toBe($this->agent->id);
});

it('acceptance: shows a tenant only their own tickets in the portal, and anyone else\'s answers 404', function () {
    $kemi = repairsPage($this->kemi)->assertOk()->assertSee('Kemi sink leaking')->assertSee('Kemi draft about the door')
        ->assertDontSee('Tunde socket sparks')->assertDontSee('Gate motor broken')->assertDontSee('Oak borehole pump down')->assertDontSee('Foreign roof leaking');
    expect(listedTickets($kemi))->toEqualCanonicalizing([$this->kemiTicket->id, $this->kemiDraft->id]);

    repairsPage($this->kemi, "/{$this->kemiTicket->id}")->assertOk()->assertSee('TKT-000001');
    foreach ([$this->tundeTicket, $this->gateTicket, $this->oakTicket, $this->foreignTicket] as $ticket) {
        repairsPage($this->kemi, "/{$ticket->id}")->assertNotFound();
        repairsPage($this->kemi, "/{$ticket->id}/edit")->assertNotFound();
    }

    // Staff are not the ticket's tenant: the portal has none of theirs.
    repairsPage($this->owner, "/{$this->kemiTicket->id}")->assertNotFound();

    // The service refuses another tenant's ticket, and a home that is not theirs.
    expect(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->confirmResolution($this->tunde, $this->kemiTicket)))->toThrow(AuthorizationException::class)
        ->and(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->updateDraft($this->tunde, $this->kemiDraft, $this->tundeLease->id, ticketInput())))->toThrow(AuthorizationException::class)
        ->and(fn () => draftTicket($this->acme, $this->tunde, $this->lease))->toThrow(ValidationException::class, 'Choose one of the homes you rent.')
        ->and(fn () => draftTicket($this->acme, $this->owner, $this->lease))->toThrow(AuthorizationException::class);
});

it('acceptance: shows a tenant of two agencies each agency\'s tickets in that agency\'s colours', function () {
    $kemiAtBello = tenantIn($this->other, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com', 'user_id' => $this->kemi->id]);
    $belloLease = activeLease($this->other, $this->otherOwner, unitIn($this->other, buildingIn($this->other, ['name' => 'Lagoon View', 'slug' => 'lagoon-view']), ['unit_label' => 'Suite 2']), $kemiAtBello, ['rent' => '900,000']);
    $this->other->forceFill(['branding' => ['trading_name' => 'Bello Homes', 'primary_color' => '#7A2E8C']])->save();
    $belloTicket = tenantTicket($this->other, $this->kemi, $belloLease, ['title' => 'Lagoon View fan broken', 'category' => 'appliance']);

    $page = repairsPage($this->kemi)->assertOk();
    $html = htmlOf($page);
    expect(listedTickets($page))->toEqualCanonicalizing([$this->kemiTicket->id, $this->kemiDraft->id, $belloTicket->id])
        ->and(substr_count($html, 'data-test="portal-agency-tickets"'))->toBe(2)
        ->and($html)->toContain('Adeyemi Realty')->toContain('Bello Homes')->toContain('Lagoon View fan broken')->toContain(BrandColours::textShade('#7A2E8C'))
        // Each agency numbers its own tickets.
        ->and(storedTicket($this->other, $belloTicket)->number)->toBe('TKT-000002');

    repairsPage($this->kemi, "/{$belloTicket->id}")->assertOk()->assertSee('Bello Homes')->assertSee('Lagoon View fan broken')->assertDontSee('Adeyemi Realty');

    // Her two homes are both offered on "Report a problem".
    repairsPage($this->kemi, '/new')->assertOk()->assertSee('Flat 3B · Palm Court')->assertSee('Suite 2 · Lagoon View');
});

it('acceptance: keeps tickets read-only while the organization is suspended, for members and tenants, and still exports them', function () {
    $show = ticketsSnapshot($this->owner, "/{$this->kemiTicket->id}");
    $create = ticketsSnapshot($this->owner, '/create');
    ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->owner, $this->tundeTicket));
    $tundeShow = repairsSnapshot($this->tunde, "/{$this->tundeTicket->id}");
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    ticketsPage($this->owner)->assertOk()->assertSee('Kemi sink leaking')->assertDontSee('data-test="add-ticket"', false)->assertSee('data-test="export-tickets-csv"', false);
    ticketsPage($this->owner, "/{$this->kemiTicket->id}")->assertOk()->assertDontSee('data-test="start-review"', false)->assertDontSee('data-test="resolve"', false)->assertDontSee('data-test="save-assignee"', false);
    ticketsPage($this->owner, '/create')->assertForbidden();
    ticketsUpdate($this->owner, $show, [], [['startReview']])->assertForbidden();
    ticketsUpdate($this->owner, $create, ['unitId' => (string) $this->gatehouse->id] + ticketInput(), [['save']])->assertForbidden();

    // Tenants see their tickets, with the notice, and raise, confirm or reopen nothing.
    repairsPage($this->kemi)->assertOk()->assertSee('Kemi sink leaking')->assertSee('data-test="portal-read-only"', false)->assertDontSee('data-test="report-problem"', false);
    repairsPage($this->kemi, '/new')->assertOk()->assertSee('No home to report for');
    repairsPage($this->tunde, "/{$this->tundeTicket->id}")->assertOk()->assertDontSee('data-test="confirm-fixed"', false)->assertDontSee('data-test="reopen-ticket"', false);
    ticketsFreshRequest();
    $this->actingAs($this->tunde);
    expect(LivewireHttp::update(test(), $tundeShow, [], [['confirm']])->status())->toBe(403);

    expect(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->startReview($this->owner, $this->kemiTicket)))->toThrow(AuthorizationException::class)
        ->and(fn () => draftTicket($this->acme, $this->kemi, $this->lease))->toThrow(AuthorizationException::class)
        ->and(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->submit($this->kemi, $this->kemiDraft)))->toThrow(AuthorizationException::class)
        ->and(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->reopenAsTenant($this->tunde, $this->tundeTicket, 'Still sparking')))->toThrow(AuthorizationException::class)
        ->and(storedTicket($this->acme, $this->kemiTicket)->status)->toBe(TicketStatus::Submitted)
        ->and(storedTicket($this->acme, $this->tundeTicket)->status)->toBe(TicketStatus::Resolved)
        ->and(storedTicket($this->acme, $this->kemiDraft)->status)->toBe(TicketStatus::Draft);

    // The tickets-auto-close job is the system's, not a member's: it still closes resolved tickets.
    $this->travelTo('2027-03-23 04:30:00');
    expect(ticketsDo($this->acme, fn (TicketService $s) => $s->autoClose($this->tundeTicket->id)))->toBeTrue();

    $pressed = ticketsUpdate($this->owner, ticketsSnapshot($this->owner), [], [['export', ['tickets', 'csv']]])->assertOk();
    expect(LivewireHttp::redirect($pressed))->toContain('/exports/');
});

it('acceptance: lets impersonating staff look at tickets in a read-only session and change nothing', function () {
    $staff = staffMember(['name' => 'Kemi Support', 'email' => 'kemi.support@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme))->assertRedirect('/o/acme-homes');

    $list = asSupport()->get('/o/acme-homes/tickets')->assertOk()->assertSee('Kemi sink leaking')->assertDontSee('data-test="add-ticket"', false)->assertSee('data-test="export-tickets-csv"', false);
    $show = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/tickets/{$this->kemiTicket->id}")->assertOk()->assertDontSee('data-test="start-review"', false));
    asSupport()->get('/o/acme-homes/tickets/create')->assertForbidden();

    freshRequest();
    LivewireHttp::update(test(), $show, [], [['startReview']])->assertForbidden();
    freshRequest();
    LivewireHttp::update(test(), $show, ['assigneeId' => (string) $this->agent->id], [['assign']])->assertForbidden();
    freshRequest();
    $pressed = LivewireHttp::update(test(), LivewireHttp::snapshot($list), [], [['export', ['tickets', 'csv']]])->assertOk();

    expect(LivewireHttp::redirect($pressed))->toContain('/exports/')
        ->and(storedTicket($this->acme, $this->kemiTicket)->status)->toBe(TicketStatus::Submitted)
        ->and(storedTicket($this->acme, $this->kemiTicket)->assigned_to_user_id)->toBeNull();
});

it('acceptance: lets impersonating staff in write mode raise, move and assign tickets, audited with both identities', function () {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write))->assertRedirect('/o/acme-homes');

    $show = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/tickets/{$this->kemiTicket->id}")->assertOk()->assertSee('data-test="start-review"', false));
    freshRequest();
    LivewireHttp::update(test(), $show, [], [['startReview']])->assertOk();
    freshRequest();
    LivewireHttp::update(test(), $show, ['assigneeId' => (string) $this->agent->id], [['assign']])->assertOk();

    $create = LivewireHttp::snapshot(asSupport()->get('/o/acme-homes/tickets/create')->assertOk());
    freshRequest();
    $building = LivewireHttp::update(test(), $create, ['propertyId' => (string) $this->palm->id])->assertOk();
    freshRequest();
    LivewireHttp::update(test(), LivewireHttp::next($building), ['unitId' => (string) $this->gatehouse->id] + ticketInput(['title' => 'Raised by support']), [['save']])->assertOk();

    $reviewed = AuditEntry::query()->where('event', 'ticket.review_started')->sole();
    $raised = AuditEntry::query()->where('event', 'ticket.raised')->latest('id')->first();
    expect(storedTicket($this->acme, $this->kemiTicket)->status)->toBe(TicketStatus::UnderReview)
        ->and(storedTicket($this->acme, $this->kemiTicket)->assigned_to_user_id)->toBe($this->agent->id)
        ->and(ticketsOf($this->acme)->pluck('title')->all())->toContain('Raised by support')
        ->and($reviewed->causer_id)->toBe($this->owner->id)
        ->and($reviewed->impersonator_staff_id)->toBe($staff->id)
        ->and($raised->impersonator_staff_id)->toBe($staff->id)
        ->and(AuditEntry::query()->where('event', 'ticket.assigned')->sole()->impersonator_staff_id)->toBe($staff->id);
});

it('acceptance: lets staff impersonating a tenant see their repairs, send nothing in a read-only session, and send in write mode, audited', function (ImpersonationMode $mode) {
    // Kemi also works for Acme as an agent: staff impersonate her in Acme.
    joinAs($this->kemi, $this->acme, OrganizationRole::Agent);
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->kemi, $this->acme, $mode))->assertRedirect();

    asSupport()->get('/portal/tickets')->assertOk()->assertSee('Kemi sink leaking');
    $draft = LivewireHttp::snapshot(asSupport()->get("/portal/tickets/{$this->kemiDraft->id}/edit")->assertOk());
    freshRequest();
    $sent = LivewireHttp::update(test(), $draft, [], [['send']]);

    if ($mode === ImpersonationMode::ReadOnly) {
        $sent->assertForbidden();
        expect(storedTicket($this->acme, $this->kemiDraft)->status)->toBe(TicketStatus::Draft);

        return;
    }

    $sent->assertOk();
    $audit = AuditEntry::query()->where('event', 'ticket.submitted')->where('subject_id', $this->kemiDraft->id)->sole();
    expect(storedTicket($this->acme, $this->kemiDraft)->status)->toBe(TicketStatus::Submitted)
        ->and($audit->causer_id)->toBe($this->kemi->id)
        ->and($audit->impersonator_staff_id)->toBe($staff->id);
})->with([
    'read-only' => [ImpersonationMode::ReadOnly],
    'write' => [ImpersonationMode::Write],
]);
