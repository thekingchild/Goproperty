<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Testing\TestResponse;
use Modules\Tickets\Lists\TicketList;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Tickets\TicketService;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/ticket-world.php';
require_once __DIR__.'/../../../../tests/Feature/Exports/helpers.php';

/*
 * P04-03 "agent views (inbox by status)": the staff tickets page is an
 * inbox with tabs by status (Open, Submitted, Under review, In progress,
 * Reopened, Resolved, Closed, All) and by assignee (Anyone, Assigned to
 * me, Unassigned), each with its count. The counts and the export follow
 * the list's own query (Lists\TicketList), so an agent narrowed to
 * buildings never counts another building's tickets, nobody counts
 * another organization's or a tenant's draft.
 *
 * World (ticket-world.php) plus six tickets: Palm Court (Bola's building)
 * — TKT-1 Kemi's, submitted, Bola's; TKT-2 Tunde's, in progress,
 * unassigned; TKT-3 the Gatehouse, resolved, Chika's; TKT-6 Kemi's,
 * reopened, unassigned — and Oak Lodge — TKT-4 Femi's, under review,
 * Chika's; TKT-5 Femi's, closed, unassigned. Kemi also keeps a draft, and
 * Bello Homes has two tickets of its own.
 */

beforeEach(function () {
    ticketWorld();

    $this->t1 = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi sink leaking']);
    ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $this->t1, $this->agent->id));

    $this->t2 = tenantTicket($this->acme, $this->tunde, $this->tundeLease, ['title' => 'Tunde socket sparks']);
    ticketsDo($this->acme, fn (TicketService $s) => $s->startWork($this->owner, $this->t2));

    $this->t3 = raiseTicket($this->acme, $this->owner, $this->gatehouse, null, ['title' => 'Gate motor broken', 'assigneeId' => $this->freeAgent->id]);
    ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->owner, $this->t3));

    $this->t4 = raiseTicket($this->acme, $this->owner, $this->oakUnit, $this->femi, ['title' => 'Oak borehole pump down', 'assigneeId' => $this->freeAgent->id]);
    ticketsDo($this->acme, fn (TicketService $s) => $s->startReview($this->owner, $this->t4));

    $this->t5 = raiseTicket($this->acme, $this->owner, $this->oakUnit, $this->femi, ['title' => 'Oak window latch']);
    ticketsDo($this->acme, function (TicketService $s) {
        $s->resolve($this->owner, $this->t5);
        $s->close($this->owner, $this->t5);
    });

    $this->t6 = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi door hinge']);
    ticketsDo($this->acme, function (TicketService $s) {
        $s->resolve($this->owner, $this->t6);
        $s->reopenAsTenant($this->kemi, $this->t6, 'Still squeaks');
    });

    draftTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi draft about the door']);
    raiseTicket($this->other, $this->otherOwner, $this->foreignUnit, $this->foreignTenant, ['title' => 'Foreign roof leaking']);
    raiseTicket($this->other, $this->otherOwner, $this->foreignUnit, null, ['title' => 'Foreign lift stuck']);
});

afterEach(fn () => ticketsFreshRequest());

/**
 * The tabs' counts on a rendered inbox: `status:open`, `assignee:me`, ….
 *
 * @return array<string, int>
 */
function inboxCounts(TestResponse|string $page): array
{
    preg_match_all('/data-test="(status|assignee)-tab-([a-z_]+)" data-count="(\d+)"/', htmlOf($page), $matches, PREG_SET_ORDER);

    $counts = [];

    foreach ($matches as [, $group, $tab, $count]) {
        $counts[$group.':'.$tab] = (int) $count;
    }

    return $counts;
}

/** @param array<string, int> $status @param array<string, int> $assignee */
function expectedInbox(array $status, array $assignee): array
{
    $counts = [];

    foreach ($status as $tab => $count) {
        $counts['status:'.$tab] = $count;
    }

    foreach ($assignee as $tab => $count) {
        $counts['assignee:'.$tab] = $count;
    }

    return $counts;
}

describe('acceptance: the staff inbox by status', function () {
    it('counts each status and assignee tab for what the member may see: an agent narrowed to a building counts that building only, nobody counts another organization\'s tickets or a draft', function () {
        expect(inboxCounts(ticketsPage($this->owner)->assertOk()))->toEqual(expectedInbox(
            ['open' => 4, 'submitted' => 1, 'under_review' => 1, 'in_progress' => 1, 'reopened' => 1, 'resolved' => 1, 'closed' => 1, 'all' => 6],
            ['all' => 6, 'me' => 0, 'none' => 3],
        ))
            ->and(inboxCounts(ticketsPage($this->agent)->assertOk()))->toEqual(expectedInbox(
                ['open' => 3, 'submitted' => 1, 'under_review' => 0, 'in_progress' => 1, 'reopened' => 1, 'resolved' => 1, 'closed' => 0, 'all' => 4],
                ['all' => 4, 'me' => 1, 'none' => 2],
            ))
            ->and(inboxCounts(ticketsPage($this->freeAgent)->assertOk()))->toEqual(expectedInbox(
                ['open' => 4, 'submitted' => 1, 'under_review' => 1, 'in_progress' => 1, 'reopened' => 1, 'resolved' => 1, 'closed' => 1, 'all' => 6],
                ['all' => 6, 'me' => 2, 'none' => 3],
            ))
            ->and(inboxCounts(ticketsPage($this->otherOwner, '', 'other-realty')->assertOk()))->toEqual(expectedInbox(
                ['open' => 2, 'submitted' => 2, 'under_review' => 0, 'in_progress' => 0, 'reopened' => 0, 'resolved' => 0, 'closed' => 0, 'all' => 2],
                ['all' => 2, 'me' => 0, 'none' => 2],
            ));
    });

    it('lists what the chosen tabs say, and each tab counts under the others\' choice, the building and the search', function () {
        // Chika's own open tickets: TKT-4 only; her own tickets by status: one open, one resolved.
        $page = ticketsPage($this->freeAgent, '?status=open&assignee=me')->assertOk();
        expect(listedTickets($page))->toBe([$this->t4->id])
            ->and(inboxCounts($page))->toMatchArray(['status:open' => 1, 'status:resolved' => 1, 'status:all' => 2, 'assignee:all' => 4, 'assignee:me' => 1, 'assignee:none' => 2]);

        // Unassigned, every status: TKT-2, TKT-5, TKT-6, newest first.
        expect(listedTickets(ticketsPage($this->owner, '?assignee=none')))->toBe([$this->t6->id, $this->t5->id, $this->t2->id])
            ->and(listedTickets(ticketsPage($this->owner, '?status=reopened')))->toBe([$this->t6->id])
            ->and(listedTickets(ticketsPage($this->owner, '?status=closed')))->toBe([$this->t5->id]);

        // Oak Lodge only, and a search: the counts follow.
        expect(inboxCounts(ticketsPage($this->owner, '?building='.$this->oak->id)))->toMatchArray(['status:open' => 1, 'status:under_review' => 1, 'status:closed' => 1, 'status:all' => 2, 'assignee:none' => 1])
            ->and(inboxCounts(ticketsPage($this->owner, '?q=Kemi')))->toMatchArray(['status:open' => 2, 'status:submitted' => 1, 'status:reopened' => 1, 'status:all' => 2]);

        // An agent cannot count another building by asking for it.
        expect(inboxCounts(ticketsPage($this->agent, '?building='.$this->oak->id)))->toMatchArray(['status:all' => 0, 'assignee:all' => 0]);
    });

    it('switches tabs on the page (the buttons set the filter) and keeps them in the address', function () {
        $snapshot = ticketsSnapshot($this->owner);

        $open = ticketsUpdate($this->owner, $snapshot, ['status' => 'open'])->assertOk();
        expect(listedTickets($open))->toBe([$this->t6->id, $this->t4->id, $this->t2->id, $this->t1->id]);

        $mine = ticketsUpdate($this->agent, ticketsSnapshot($this->agent), ['status' => 'open', 'assignee' => 'me'])->assertOk();
        expect(listedTickets($mine))->toBe([$this->t1->id])
            ->and(htmlOf($mine))->toContain('aria-pressed="true"');

        // The address keeps the tabs (shared links open the same view).
        ticketsPage($this->owner, '?status=in_progress&assignee=none')->assertOk()->assertSee('data-test="status-tab-in_progress" data-count="1"', false)
            ->assertSee('aria-current="true"', false);
        expect(listedTickets(ticketsPage($this->owner, '?status=in_progress&assignee=none')))->toBe([$this->t2->id]);
    });

    it('exports what the tabs show, narrowed for an agent', function () {
        $rows = exportRows($this->agent, '/tickets?status=open&assignee=none', 'tickets');
        expect(exportColumn($rows, 'Number'))->toBe(['TKT-000006', 'TKT-000002']);

        $mine = exportRows($this->freeAgent, '/tickets?assignee=me', 'tickets');
        expect(exportColumn($mine, 'Number'))->toBe(['TKT-000004', 'TKT-000003'])
            ->and(exportColumn($mine, 'Assigned to'))->toBe(['Chika Agent', 'Chika Agent']);

        // The list's own count (the export's) agrees with the tabs.
        expect(inOrganization($this->acme, fn () => app(TicketList::class)->count($this->agent, app(TicketList::class)->filters(['status' => 'open', 'assignee' => 'none']))))->toBe(2)
            ->and(inOrganization($this->acme, fn () => app(TicketList::class)->count($this->agent, app(TicketList::class)->filters(['assignee' => 'nobody', 'status' => 'nonsense']))))->toBe(4);
    });

    it('stays an inbox while the organization is suspended and in a read-only support session: tabs switch, counts show, the export works', function () {
        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $tabbed = ticketsUpdate($this->owner, ticketsSnapshot($this->owner), ['status' => 'closed'])->assertOk();
        expect(listedTickets($tabbed))->toBe([$this->t5->id]);
        $this->acme->forceFill(['status' => OrganizationStatus::Active])->save();

        $staff = staffMember(['name' => 'Kemi Support', 'email' => 'kemi.support@goproperty.ng']);
        enterSupport(startSupport($staff, $this->agent, $this->acme, ImpersonationMode::ReadOnly))->assertRedirect('/o/acme-homes');
        $page = asSupport()->get('/o/acme-homes/tickets')->assertOk();
        expect(inboxCounts($page))->toMatchArray(['status:open' => 3, 'assignee:me' => 1]);
        freshRequest();
        $mine = LivewireHttp::update(test(), LivewireHttp::snapshot($page), ['assignee' => 'me'])->assertOk();
        expect(listedTickets($mine))->toBe([$this->t1->id]);
    });
});

describe('acceptance: the tenant inbox by status', function () {
    it('shows a tenant their open repairs — fixed and waiting for them, being dealt with, drafts — apart from the finished ones, with counts of their own tickets only', function () {
        $fixed = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi bathroom tap']);
        ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->owner, $fixed));
        $done = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi blocked drain']);
        ticketsDo($this->acme, function (TicketService $s) use ($done) {
            $s->resolve($this->owner, $done);
            $s->confirmResolution($this->kemi, $done);
        });

        $open = repairsPage($this->kemi)->assertOk()->assertDontSee('autofocus', false);
        $html = htmlOf($open);

        expect(listedTickets($open))->toEqualCanonicalizing([$fixed->id, $this->t1->id, $this->t6->id, inOrganization($this->acme, fn () => Ticket::query()->where('title', 'Kemi draft about the door')->sole()->id)])
            ->and($html)->toContain('data-test="portal-tab-open" data-count="4"')->toContain('data-test="portal-tab-finished" data-count="1"')
            ->toContain('Fixed? Please check')->toContain('Being dealt with')->toContain('Drafts: not sent yet')
            ->not->toContain('Kemi blocked drain')->not->toContain('Tunde socket sparks')->not->toContain('Gate motor broken')
            // "Fixed? Please check" comes first: it is the one waiting for her.
            ->and(strpos($html, 'Kemi bathroom tap'))->toBeLessThan(strpos($html, 'Kemi door hinge'));

        $finished = repairsPage($this->kemi, '?show=finished')->assertOk();
        expect(listedTickets($finished))->toBe([$done->id])
            ->and(htmlOf($finished))->toContain('aria-pressed="true"')->not->toContain('Kemi bathroom tap');

        // The tab switches on the page too.
        $switched = LivewireHttp::update(test(), repairsSnapshot($this->kemi), ['show' => 'finished'])->assertOk();
        expect(listedTickets($switched))->toBe([$done->id]);

        // Tunde sees his own count only.
        expect(htmlOf(repairsPage($this->tunde)))->toContain('data-test="portal-tab-open" data-count="1"')->toContain('data-test="portal-tab-finished" data-count="0"');
    });

    it('counts the open and finished repairs of every agency together and shows each agency\'s under its name', function () {
        $kemiAtBello = tenantIn($this->other, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com', 'user_id' => $this->kemi->id]);
        $belloLease = activeLease($this->other, $this->otherOwner, unitIn($this->other, buildingIn($this->other, ['name' => 'Lagoon View', 'slug' => 'lagoon-view']), ['unit_label' => 'Suite 2']), $kemiAtBello, ['rent' => '900,000']);
        $belloTicket = tenantTicket($this->other, $this->kemi, $belloLease, ['title' => 'Lagoon View fan broken']);
        ticketsDo($this->other, function (TicketService $s) use ($belloTicket) {
            $s->resolve($this->otherOwner, $belloTicket);
            $s->close($this->otherOwner, $belloTicket);
        });

        $open = htmlOf(repairsPage($this->kemi));
        $finished = htmlOf(repairsPage($this->kemi, '?show=finished'));

        expect($open)->toContain('data-test="portal-tab-open" data-count="3"')->toContain('data-test="portal-tab-finished" data-count="1"')
            ->toContain('Nothing open with Bello Homes Ltd.')->not->toContain('Lagoon View fan broken')
            ->and($finished)->toContain('Lagoon View fan broken')->toContain('No finished repairs with Adeyemi Realty Ltd yet.');
    });
});
