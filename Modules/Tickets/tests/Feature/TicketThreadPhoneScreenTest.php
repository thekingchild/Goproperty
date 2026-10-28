<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use Illuminate\Testing\TestResponse;
use Modules\Tickets\Tickets\TicketService;
use Tests\Support\NarrowScreen;

require_once __DIR__.'/message-world.php';
require_once __DIR__.'/../../../../tests/Support/documents.php';

/*
 * P04-02: the portal pages with the conversation pass the 360 px check
 * (UX_DESIGN.md § 1, as P04-01's TicketPortalPhoneScreenTest): the
 * tenant's ticket with a thread (long words, photos, files) and its reply
 * form, and the owner's repairs list and ticket (reply form, and the
 * "an agent is handling it" state), each laid out in real Chrome
 * (Gotenberg's screenshot route) at 360 CSS pixels: no sideways scroll, no
 * visible tap target under 44 × 44 px (Tests\Support\NarrowScreen).
 *
 * Skipped locally only when Gotenberg does not answer (PDF_CHROME_URL); on
 * CI an unreachable Gotenberg fails the test (orchestrator decision 6).
 */

beforeEach(function () {
    messageWorld();
});

afterEach(fn () => ticketsFreshRequest());

/**
 * @param  array<string, TestResponse>  $pages
 * @return array<string, array{viewport: int, overflow: int, targets: int, small: int}>
 */
function threadPagesFailingOnPhones(string $gotenberg, array $pages): array
{
    $failing = [];

    foreach ($pages as $name => $page) {
        expect($page->status())->toBe(200, $name);
        $screen = NarrowScreen::inspect($gotenberg, NarrowScreen::standalone((string) $page->getContent()), 360);

        if ($screen['viewport'] !== 360 || $screen['overflow'] !== 0 || $screen['small'] !== 0) {
            $failing[$name] = $screen;
        }
    }

    return $failing;
}

describe('the conversation on phones', function () {
    it('lays out the tenant\'s and the owner\'s ticket pages with a thread on a 360 px screen: no sideways scrolling, 44 px targets', function () {
        $gotenberg = requireGotenberg();

        $ticket = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Water heater not heating up in the bathroom since the weekend', 'priority' => 'urgent']);
        tenantReply($this->acme, $this->kemi, $ticket, 'It started on Saturday night: https://example.com/a-very-long-link-without-any-spaces-that-must-wrap-instead-of-scrolling', [ticketPhoto('heater.jpg'), ticketHeic('IMG_20270312_084512_very_long_iphone_name.HEIC'), ticketPdf('electricity-company-letter-about-the-outage.pdf')]);
        staffReply($this->acme, $this->agent, $ticket, 'Thanks. An electrician will come tomorrow between 10 and 12.');
        staffReply($this->acme, $this->agent, $ticket, INTERNAL_NOTE, internal: true);
        ownerReply($this->acme, $this->ngozi, $ticket, 'Please keep me posted.');

        $assigned = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Blocked kitchen drain']);
        ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $assigned, $this->agent->id));
        staffReply($this->acme, $this->agent, $assigned, 'On my way.');

        $resolved = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Socket sparks in the bedroom', 'category' => 'electrical']);
        staffReply($this->acme, $this->agent, $resolved, 'Replaced the socket.', [ticketPhoto('socket.png', 'png')]);
        ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->agent, $resolved, 'Replaced the socket and checked the circuit breaker.'));

        $failing = threadPagesFailingOnPhones($gotenberg, [
            'tenant: ticket with a thread' => repairsPage($this->kemi, "/{$ticket->id}"),
            'tenant: resolved ticket with a thread (confirm or reopen)' => repairsPage($this->kemi, "/{$resolved->id}"),
            'owner: repairs' => ownerTicketsPage($this->ngozi),
            'owner: ticket with the reply form' => ownerTicketsPage($this->ngozi, "/{$ticket->id}"),
            'owner: ticket an agent handles' => ownerTicketsPage($this->ngozi, "/{$assigned->id}"),
            'owner: repairs (none)' => ownerTicketsPage($this->femiOwner),
        ]);

        expect($failing)->toBe([]);
    });

    it('lays out the read-only states on a 360 px screen too', function () {
        $gotenberg = requireGotenberg();
        $ticket = tenantTicket($this->acme, $this->kemi, $this->lease);
        staffReply($this->acme, $this->agent, $ticket, 'We will come on Monday.');
        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $failing = threadPagesFailingOnPhones($gotenberg, [
            'tenant: ticket (agency suspended)' => repairsPage($this->kemi, "/{$ticket->id}"),
            'owner: ticket (agency suspended)' => ownerTicketsPage($this->ngozi, "/{$ticket->id}"),
        ]);

        expect($failing)->toBe([]);
    });
});
