<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Settings\SettingsStore;
use App\Tenancy\OrganizationContext;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Events\TicketStatusChanged;
use Modules\Tickets\Jobs\CloseResolvedTickets;
use Modules\Tickets\Tickets\TicketService;
use Modules\Tickets\Tickets\TicketSettings;

require_once __DIR__.'/ticket-world.php';

/*
 * P04-01 acceptance: "auto-close closes resolved tickets after the
 * configured days exactly once" (docs/plans/P04-subtasks.md default 2):
 * the daily `tickets-auto-close` job (ARCHITECTURE.md § 7, 04:30) closes a
 * resolved ticket the tenant has neither confirmed nor reopened once the
 * organization's `tickets.auto_close_days` (default 7) have passed since
 * its resolution; per organization, idempotent, through TicketService
 * (history row, audit entry, TicketStatusChanged with no causer).
 */

beforeEach(function () {
    ticketWorld();
});

afterEach(fn () => ticketsFreshRequest());

/** Runs the scheduled `tickets-auto-close` event, as the scheduler does. */
function runTicketSchedule(): void
{
    $event = collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event): bool => $event->description === 'tickets-auto-close');

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe('30 4 * * *');

    $event->run(app());
}

it('acceptance: closes a resolved ticket nobody answered once the configured days have passed, exactly once', function () {
    $ticket = tenantTicket($this->acme, $this->kemi, $this->lease);
    ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->agent, $ticket, 'Replaced the trap.'));
    $closings = [];
    Event::listen(TicketStatusChanged::class, function (TicketStatusChanged $event) use (&$closings): void {
        if ($event->to === 'closed') {
            $closings[] = [$event->ticketId, $event->from, $event->actor, $event->actorUserId];
        }
    });

    // Resolved 15 March 10:00: still open a second before 22 March 10:00.
    $this->travelTo('2027-03-22 09:59:59');
    runTicketSchedule();
    expect(storedTicket($this->acme, $ticket)->status)->toBe(TicketStatus::Resolved)
        ->and($closings)->toBe([]);

    $this->travelTo('2027-03-23 04:30:00');
    runTicketSchedule();

    $stored = storedTicket($this->acme, $ticket);
    expect($stored->status)->toBe(TicketStatus::Closed)
        ->and($stored->closed_at?->toDateTimeString())->toBe('2027-03-23 04:30:00')
        ->and(array_slice(ticketHistory($this->acme, $ticket), -1)[0])->toBe(['resolved', 'closed', null, 'Closed automatically: no answer 7 days after it was resolved.'])
        ->and($closings)->toBe([[$ticket->id, 'resolved', 'system', null]]);

    $audit = AuditEntry::query()->where('event', 'ticket.closed')->sole();
    expect($audit->causer_id)->toBeNull()
        ->and($audit->properties['by'])->toBe('system')
        ->and($audit->properties['auto_close_days'])->toBe(7);

    // Run again, the next day, and the job twice in a row: nothing more happens.
    runTicketSchedule();
    $this->travelTo('2027-03-24 04:30:00');
    (new CloseResolvedTickets)->handle(app(OrganizationContext::class), app(TicketService::class), app(TicketSettings::class));
    runTicketSchedule();

    expect(ticketHistory($this->acme, $ticket))->toHaveCount(4)
        ->and(AuditEntry::query()->where('event', 'ticket.closed')->count())->toBe(1)
        ->and($closings)->toHaveCount(1)
        ->and(storedTicket($this->acme, $ticket)->closed_at?->toDateTimeString())->toBe('2027-03-23 04:30:00');
});

it('leaves alone tickets that are not resolved, a reopened one, and one the tenant already confirmed', function () {
    $waiting = raiseTicket($this->acme, $this->owner, $this->flat4c, $this->tundeAtAcme);
    $reopened = tenantTicket($this->acme, $this->kemi, $this->lease);
    ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->agent, $reopened));
    ticketsDo($this->acme, fn (TicketService $s) => $s->reopenAsTenant($this->kemi, $reopened, 'Still dripping.'));
    $confirmed = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Socket sparks']);
    ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->agent, $confirmed));
    $this->travelTo('2027-03-16 09:00:00');
    ticketsDo($this->acme, fn (TicketService $s) => $s->confirmResolution($this->kemi, $confirmed));
    $draft = draftTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Draft only']);

    $this->travelTo('2027-06-01 04:30:00');
    runTicketSchedule();

    expect(storedTicket($this->acme, $waiting)->status)->toBe(TicketStatus::Submitted)
        ->and(storedTicket($this->acme, $reopened)->status)->toBe(TicketStatus::Reopened)
        ->and(storedTicket($this->acme, $confirmed)->closed_at?->toDateTimeString())->toBe('2027-03-16 09:00:00')
        ->and(storedTicket($this->acme, $draft)->status)->toBe(TicketStatus::Draft)
        ->and(AuditEntry::query()->where('event', 'ticket.closed')->count())->toBe(1);
});

it('follows each organization\'s own number of days', function () {
    inOrganization($this->acme, fn () => app(SettingsStore::class)->set(TicketSettings::AUTO_CLOSE_DAYS, 2, $this->acme));
    $acme = raiseTicket($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
    ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->owner, $acme));
    $foreign = raiseTicket($this->other, $this->otherOwner, $this->foreignUnit, $this->foreignTenant);
    ticketsDo($this->other, fn (TicketService $s) => $s->resolve($this->otherOwner, $foreign));

    $this->travelTo('2027-03-18 04:30:00');
    runTicketSchedule();

    expect(storedTicket($this->acme, $acme)->status)->toBe(TicketStatus::Closed)
        ->and(array_slice(ticketHistory($this->acme, $acme), -1)[0][3])->toBe('Closed automatically: no answer 2 days after it was resolved.')
        ->and(storedTicket($this->other, $foreign)->status)->toBe(TicketStatus::Resolved);

    $this->travelTo('2027-03-23 04:30:00');
    runTicketSchedule();
    expect(storedTicket($this->other, $foreign)->status)->toBe(TicketStatus::Closed);
});

it('refuses an early automatic close and one of a ticket no longer resolved', function () {
    $ticket = raiseTicket($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);

    expect(ticketsDo($this->acme, fn (TicketService $s) => $s->autoClose($ticket->id)))->toBeFalse();

    ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->owner, $ticket));
    $this->travelTo('2027-03-21 10:00:00');
    expect(ticketsDo($this->acme, fn (TicketService $s) => $s->autoClose($ticket->id)))->toBeFalse()
        ->and(storedTicket($this->acme, $ticket)->status)->toBe(TicketStatus::Resolved);

    $this->travelTo('2027-03-22 10:00:00');
    expect(ticketsDo($this->acme, fn (TicketService $s) => $s->autoClose($ticket->id)))->toBeTrue()
        ->and(ticketsDo($this->acme, fn (TicketService $s) => $s->autoClose($ticket->id)))->toBeFalse();
});
