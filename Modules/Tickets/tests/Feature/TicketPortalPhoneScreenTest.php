<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Modules\Tickets\Tickets\TicketService;
use Tests\Support\NarrowScreen;

require_once __DIR__.'/ticket-world.php';
require_once __DIR__.'/../../../../tests/Support/documents.php';

/*
 * P04-01 acceptance: the tenant portal's repair pages pass the 360 px check
 * (UX_DESIGN.md § 1: tenants use low-end Android phones; every tenant
 * screen is designed at 360 px first, with 44 px touch targets), as the
 * portal's other pages (P03-11b, Modules/Tenants/tests/Feature/Portal/
 * PortalPhoneScreenTest.php): each page is opened as the tenant's browser
 * gets it, laid out in real Chrome (Gotenberg's screenshot route) at 360
 * CSS pixels, and must not scroll sideways nor have a visible tap target
 * smaller than 44 × 44 px (Tests\Support\NarrowScreen::inspect).
 *
 * Skipped locally only when Gotenberg does not answer (PDF_CHROME_URL); on
 * CI an unreachable Gotenberg fails the test (orchestrator decision 6).
 */

beforeEach(function () {
    ticketWorld();
});

afterEach(fn () => ticketsFreshRequest());

/**
 * Lays each page out at 360 px; the pages that scroll sideways or have
 * small targets, with what was measured.
 *
 * @param  array<string, TestResponse>  $pages
 * @return array<string, array{viewport: int, overflow: int, targets: int, small: int}>
 */
function repairPagesFailingOnPhones(string $gotenberg, array $pages): array
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

describe('acceptance: repair pages usable at 360 px width', function () {
    it('lays out the repairs list, the form, a draft and a ticket in each state on a 360 px screen: no sideways scrolling, 44 px targets', function () {
        $gotenberg = requireGotenberg();

        $draft = draftTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Front door lock sticks', 'description' => '']);
        $waiting = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Water heater not heating up in the bathroom since the weekend', 'priority' => 'urgent']);
        $resolved = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Socket sparks in the bedroom', 'category' => 'electrical']);
        ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->agent, $resolved, 'Replaced the socket and checked the circuit breaker.'));
        $closed = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Blocked kitchen drain']);
        ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->agent, $closed));
        ticketsDo($this->acme, fn (TicketService $s) => $s->confirmResolution($this->kemi, $closed));
        $staff = raiseTicket($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme, ['title' => 'Annual gas check', 'category' => 'appliance']);

        $failing = repairPagesFailingOnPhones($gotenberg, [
            'repairs' => repairsPage($this->kemi),
            'repairs, finished (P04-03 inbox)' => repairsPage($this->kemi, '?show=finished'),
            'report a problem' => repairsPage($this->kemi, '/new'),
            'draft' => repairsPage($this->kemi, "/{$draft->id}/edit"),
            'waiting ticket' => repairsPage($this->kemi, "/{$waiting->id}"),
            'resolved ticket (confirm or reopen)' => repairsPage($this->kemi, "/{$resolved->id}"),
            'closed ticket (reopen)' => repairsPage($this->kemi, "/{$closed->id}"),
            'ticket raised by the agency' => repairsPage($this->kemi, "/{$staff->id}"),
        ]);

        expect($failing)->toBe([]);
    });

    it('lays out the empty and read-only states on a 360 px screen too', function () {
        $gotenberg = requireGotenberg();
        $nobody = User::factory()->create(['name' => 'Ngozi Eze', 'email' => 'ngozi.eze@example.com']);
        $ticket = tenantTicket($this->acme, $this->kemi, $this->lease);
        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $failing = repairPagesFailingOnPhones($gotenberg, [
            'repairs (none)' => repairsPage($nobody),
            'report a problem (no home)' => repairsPage($nobody, '/new'),
            'repairs (agency suspended)' => repairsPage($this->kemi),
            'ticket (agency suspended)' => repairsPage($this->kemi, "/{$ticket->id}"),
            'report a problem (agency suspended)' => repairsPage($this->kemi, '/new'),
        ]);

        expect($failing)->toBe([]);
    });
});
