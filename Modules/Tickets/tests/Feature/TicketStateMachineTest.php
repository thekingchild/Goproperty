<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Members\Members;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Leasing\Leases\LeaseService;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Events\TicketStatusChanged;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Tickets\TicketRefused;
use Modules\Tickets\Tickets\TicketService;

require_once __DIR__.'/ticket-world.php';

/*
 * P04-01 acceptance: "Transitions invalid per the state machine are
 * rejected (tested)" (IMPLEMENTATION_PLAN.md § 3; ARCHITECTURE.md § 6.6).
 * Every move TicketService offers is tried from every status: the moves the
 * state machine (TicketStatus::canMoveTo) allows happen, with one history
 * row, one audit entry and one TicketStatusChanged; every other one is
 * refused and changes nothing (the row, its history, the audit log, no
 * event). Nothing moves a ticket back to `draft`: no method exists, and the
 * table says so.
 */

beforeEach(function () {
    ticketWorld();
});

afterEach(fn () => ticketsFreshRequest());

/**
 * Each move TicketService offers: the status it moves to, who makes it
 * (the tenant Kemi or the owner Ada) and the call.
 *
 * @return array<string, array{TicketStatus, string, Closure(TicketService, User, Ticket): Ticket}>
 */
function ticketMoves(): array
{
    return [
        'submit (tenant)' => [TicketStatus::Submitted, 'tenant', fn (TicketService $s, $by, Ticket $t) => $s->submit($by, $t)],
        'start review (staff)' => [TicketStatus::UnderReview, 'staff', fn (TicketService $s, $by, Ticket $t) => $s->startReview($by, $t)],
        'start work (staff)' => [TicketStatus::InProgress, 'staff', fn (TicketService $s, $by, Ticket $t) => $s->startWork($by, $t)],
        'resolve (staff)' => [TicketStatus::Resolved, 'staff', fn (TicketService $s, $by, Ticket $t) => $s->resolve($by, $t, 'Replaced the trap and the seal.')],
        'confirm resolution (tenant)' => [TicketStatus::Closed, 'tenant', fn (TicketService $s, $by, Ticket $t) => $s->confirmResolution($by, $t)],
        'close (staff)' => [TicketStatus::Closed, 'staff', fn (TicketService $s, $by, Ticket $t) => $s->close($by, $t, 'Tenant confirmed on the phone.')],
        'reopen (staff)' => [TicketStatus::Reopened, 'staff', fn (TicketService $s, $by, Ticket $t) => $s->reopen($by, $t, 'The leak is back.')],
        'reopen (tenant)' => [TicketStatus::Reopened, 'tenant', fn (TicketService $s, $by, Ticket $t) => $s->reopenAsTenant($by, $t, 'Still dripping at night.')],
    ];
}

/** Kemi's ticket in $status (a draft stays a draft; resolved and closed ones were resolved today). */
function kemiTicketIn(TicketStatus $status): Ticket
{
    if ($status === TicketStatus::Draft) {
        return draftTicket(test()->acme, test()->kemi, test()->lease);
    }

    $ticket = tenantTicket(test()->acme, test()->kemi, test()->lease);

    return $status === TicketStatus::Submitted ? $ticket : forceTicketStatus(test()->acme, $ticket, $status->value, [
        'resolved_at' => in_array($status, [TicketStatus::Resolved, TicketStatus::Closed, TicketStatus::Reopened], true) ? now() : null,
        'closed_at' => $status === TicketStatus::Closed ? now() : null,
    ]);
}

/** @return array<string, array{TicketStatus, string}> every (from status, move) pair */
function everyTicketMove(): array
{
    $pairs = [];

    foreach (TicketStatus::cases() as $from) {
        foreach (array_keys(ticketMoves()) as $move) {
            $pairs["{$from->value} → {$move}"] = [$from, $move];
        }
    }

    return $pairs;
}

it('acceptance: allows exactly the moves of the state machine and refuses every other one, changing nothing', function (TicketStatus $from, string $move) {
    [$to, $actor, $call] = ticketMoves()[$move];
    $ticket = kemiTicketIn($from);
    $by = $actor === 'tenant' ? $this->kemi : $this->owner;
    $before = storedTicket($this->acme, $ticket)->getAttributes();
    $history = ticketHistory($this->acme, $ticket);
    $audits = AuditEntry::query()->where('log_name', 'tickets')->count();
    Event::fake([TicketStatusChanged::class]);

    $allowed = $from->canMoveTo($to);

    try {
        $moved = inOrganization($this->acme, fn (): Ticket => $call(app(TicketService::class), $by, storedTicket($this->acme, $ticket)));
        $refused = null;
    } catch (TicketRefused|AuthorizationException $exception) {
        $refused = $exception;
    }

    if (! $allowed) {
        // A tenant's draft is nobody else's: members get 404 before the state machine is asked.
        expect($refused)->not->toBeNull("{$from->value} → {$to->value} must be refused")
            ->and($refused instanceof TicketRefused || ($from === TicketStatus::Draft && $actor === 'staff'))->toBeTrue()
            ->and(storedTicket($this->acme, $ticket)->getAttributes())->toBe($before)
            ->and(ticketHistory($this->acme, $ticket))->toBe($history)
            ->and(AuditEntry::query()->where('log_name', 'tickets')->count())->toBe($audits);
        Event::assertNotDispatched(TicketStatusChanged::class);

        return;
    }

    expect($refused)->toBeNull("{$from->value} → {$to->value} must be allowed")
        ->and($moved->status)->toBe($to)
        ->and(storedTicket($this->acme, $ticket)->status)->toBe($to)
        ->and(ticketHistory($this->acme, $ticket))->toHaveCount(count($history) + 1)
        ->and(array_slice(ticketHistory($this->acme, $ticket), -1)[0][0])->toBe($from->value)
        ->and(array_slice(ticketHistory($this->acme, $ticket), -1)[0][1])->toBe($to->value)
        ->and(array_slice(ticketHistory($this->acme, $ticket), -1)[0][2])->toBe($by->id)
        ->and(AuditEntry::query()->where('log_name', 'tickets')->count())->toBe($audits + 1);
    Event::assertDispatchedTimes(TicketStatusChanged::class, 1);
    Event::assertDispatched(TicketStatusChanged::class, fn (TicketStatusChanged $event): bool => $event->ticketId === $ticket->id
        && $event->from === $from->value && $event->to === $to->value && $event->actor === $actor && $event->actorUserId === $by->id);
})->with(everyTicketMove());

it('keeps the state machine\'s table: nothing goes back to draft, nothing skips resolved on the way to closed', function () {
    $allowed = [];

    foreach (TicketStatus::cases() as $from) {
        foreach (TicketStatus::cases() as $to) {
            if ($from->canMoveTo($to)) {
                $allowed[] = "{$from->value}→{$to->value}";
            }
        }
    }

    expect($allowed)->toBe([
        'draft→submitted',
        'submitted→under_review', 'submitted→in_progress', 'submitted→resolved',
        'under_review→in_progress', 'under_review→resolved',
        'in_progress→resolved',
        'resolved→closed', 'resolved→reopened',
        'closed→reopened',
        'reopened→under_review', 'reopened→in_progress', 'reopened→resolved',
    ])
        // Every target of the table has a method of TicketService (ticketMoves), draft has none.
        ->and(array_values(array_unique(array_map(fn (array $move): string => $move[0]->value, ticketMoves()))))->toEqualCanonicalizing(['submitted', 'under_review', 'in_progress', 'resolved', 'closed', 'reopened'])
        ->and(array_filter(get_class_methods(TicketService::class), fn (string $method): bool => str_contains(strtolower($method), 'todraft')))->toBe([]);
});

it('acceptance: records a status history row and an audit entry for each transition of a ticket\'s life', function () {
    $ticket = tenantTicket($this->acme, $this->kemi, $this->lease);
    ticketsDo($this->acme, fn (TicketService $s) => $s->startReview($this->admin, $ticket));
    ticketsDo($this->acme, fn (TicketService $s) => $s->startWork($this->agent, $ticket));
    ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->agent, $ticket, 'Replaced the trap.'));
    ticketsDo($this->acme, fn (TicketService $s) => $s->reopenAsTenant($this->kemi, $ticket, 'Still dripping at night.'));
    ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->agent, $ticket, 'New seal fitted.'));
    ticketsDo($this->acme, fn (TicketService $s) => $s->confirmResolution($this->kemi, $ticket));

    expect(ticketHistory($this->acme, $ticket))->toBe([
        [null, 'draft', $this->kemi->id, null],
        ['draft', 'submitted', $this->kemi->id, null],
        ['submitted', 'under_review', $this->admin->id, null],
        ['under_review', 'in_progress', $this->agent->id, null],
        ['in_progress', 'resolved', $this->agent->id, 'Replaced the trap.'],
        ['resolved', 'reopened', $this->kemi->id, 'Still dripping at night.'],
        ['reopened', 'resolved', $this->agent->id, 'New seal fitted.'],
        ['resolved', 'closed', $this->kemi->id, null],
    ]);

    $audits = AuditEntry::query()->where('log_name', 'tickets')->where('subject_id', $ticket->id)->orderBy('id')->get();
    expect($audits->map(fn (AuditEntry $entry): array => [$entry->event, $entry->causer_id, $entry->properties['from'] ?? null, $entry->properties['to'] ?? null])->all())->toBe([
        ['ticket.drafted', $this->kemi->id, null, 'draft'],
        ['ticket.submitted', $this->kemi->id, 'draft', 'submitted'],
        ['ticket.review_started', $this->admin->id, 'submitted', 'under_review'],
        ['ticket.work_started', $this->agent->id, 'under_review', 'in_progress'],
        ['ticket.resolved', $this->agent->id, 'in_progress', 'resolved'],
        ['ticket.reopened', $this->kemi->id, 'resolved', 'reopened'],
        ['ticket.resolved', $this->agent->id, 'reopened', 'resolved'],
        ['ticket.closed', $this->kemi->id, 'resolved', 'closed'],
    ])
        ->and($audits->every(fn (AuditEntry $entry): bool => $entry->properties['number'] === 'TKT-000001' || $entry->event === 'ticket.drafted'))->toBeTrue()
        ->and($audits[5]->properties['note'])->toBe('Still dripping at night.')
        ->and($audits[5]->properties['by'])->toBe('tenant');

    $stored = storedTicket($this->acme, $ticket);
    expect($stored->status)->toBe(TicketStatus::Closed)
        ->and($stored->number)->toBe('TKT-000001')
        ->and($stored->submitted_at?->toDateTimeString())->toBe('2027-03-15 10:00:00')
        ->and($stored->closed_at)->not->toBeNull()
        ->and($stored->reopen_count)->toBe(1);
});

it('records a staff ticket\'s creation as its first history row, and sends the event for it', function () {
    Event::fake([TicketStatusChanged::class]);
    $ticket = raiseTicket($this->acme, $this->agent, $this->gatehouse, null, ['title' => 'Gate motor not working', 'category' => 'security']);

    expect(ticketHistory($this->acme, $ticket))->toBe([[null, 'submitted', $this->agent->id, null]])
        ->and(AuditEntry::query()->where('event', 'ticket.raised')->sole()->causer_id)->toBe($this->agent->id);
    Event::assertDispatched(TicketStatusChanged::class, fn (TicketStatusChanged $event): bool => $event->from === null && $event->to === 'submitted' && $event->actor === 'staff' && $event->tenantProfileId === null);
});

it('sends no event for a tenant\'s unsent draft, and sends TicketStatusChanged only after the move commits', function () {
    $seen = [];
    Event::listen(TicketStatusChanged::class, function (TicketStatusChanged $event) use (&$seen): void {
        $seen[] = [$event->from, $event->to];
    });

    $draft = draftTicket($this->acme, $this->kemi, $this->lease);
    expect($seen)->toBe([]);

    // A move rolled back by the caller's transaction is never announced.
    try {
        DB::transaction(function () use ($draft): void {
            inOrganization($this->acme, fn () => app(TicketService::class)->submit($this->kemi, $draft));
            throw new DomainException('Something after the move failed.');
        });
    } catch (DomainException) {
    }

    expect($seen)->toBe([])
        ->and(storedTicket($this->acme, $draft)->status)->toBe(TicketStatus::Draft)
        ->and(storedTicket($this->acme, $draft)->number)->toBeNull();

    inOrganization($this->acme, fn () => app(TicketService::class)->submit($this->kemi, $draft));
    expect($seen)->toBe([['draft', 'submitted']]);
});

it('refuses to send a draft without a description, or once its home is no longer the tenant\'s, changing nothing', function () {
    $draft = draftTicket($this->acme, $this->kemi, $this->lease, ['description' => '']);

    expect(fn () => inOrganization($this->acme, fn () => app(TicketService::class)->submit($this->kemi, $draft)))->toThrow(TicketRefused::class, 'Describe the problem before sending it.');

    $other = draftTicket($this->acme, $this->kemi, $this->lease);
    inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, $this->lease, 'Tenant moved out early'));

    expect(fn () => inOrganization($this->acme, fn () => app(TicketService::class)->submit($this->kemi, $other)))->toThrow(ValidationException::class, 'Choose one of the homes you rent.')
        ->and(storedTicket($this->acme, $draft)->status)->toBe(TicketStatus::Draft)
        ->and(storedTicket($this->acme, $other)->status)->toBe(TicketStatus::Draft)
        ->and(storedTicket($this->acme, $other)->number)->toBeNull()
        ->and(ticketHistory($this->acme, $other))->toHaveCount(1);
});

it('refuses to change a sent ticket as a draft, or to discard it, and never deletes a ticket for good', function () {
    $ticket = tenantTicket($this->acme, $this->kemi, $this->lease);

    expect(fn () => inOrganization($this->acme, fn () => app(TicketService::class)->updateDraft($this->kemi, $ticket, $this->lease->id, ticketInput(['title' => 'Changed after sending']))))->toThrow(TicketRefused::class)
        ->and(fn () => inOrganization($this->acme, fn () => app(TicketService::class)->discardDraft($this->kemi, $ticket)))->toThrow(TicketRefused::class)
        ->and(storedTicket($this->acme, $ticket)->title)->toBe('Kitchen sink leaking')
        ->and(storedTicket($this->acme, $ticket)->trashed())->toBeFalse()
        ->and(fn () => inOrganization($this->acme, fn () => storedTicket($this->acme, $ticket)->forceDelete()))->toThrow(LogicException::class);

    // A draft is discarded as a soft delete, audited.
    $draft = draftTicket($this->acme, $this->kemi, $this->lease);
    inOrganization($this->acme, fn () => app(TicketService::class)->discardDraft($this->kemi, $draft));
    expect(storedTicket($this->acme, $draft)->trashed())->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'ticket.draft_discarded')->sole()->subject_id)->toBe($draft->id);
});

it('assigns only open tickets, to members who work on the building, and audits each change', function () {
    $ticket = raiseTicket($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
    $oakTicket = raiseTicket($this->acme, $this->owner, $this->oakUnit, $this->femi);

    ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $ticket, $this->agent->id));
    ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $ticket, $this->freeAgent->id));
    ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $ticket, null));

    // Bola works on Palm Court only; a tenant or another organization's member never.
    expect(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $oakTicket, $this->agent->id)))->toThrow(ValidationException::class, 'Choose a member who works on this building.')
        ->and(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $oakTicket, $this->kemi->id)))->toThrow(ValidationException::class)
        ->and(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $oakTicket, $this->otherOwner->id)))->toThrow(ValidationException::class)
        ->and(storedTicket($this->acme, $oakTicket)->assigned_to_user_id)->toBeNull();

    forceTicketStatus($this->acme, $ticket, 'closed', ['resolved_at' => now(), 'closed_at' => now()]);
    expect(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $ticket, $this->agent->id)))->toThrow(TicketRefused::class)
        ->and(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->changeDetails($this->owner, $ticket, ['category' => 'electrical', 'priority' => 'urgent'])))->toThrow(TicketRefused::class);

    expect(AuditEntry::query()->where('subject_id', $ticket->id)->whereIn('event', ['ticket.assigned', 'ticket.unassigned'])->orderBy('id')->get()
        ->map(fn (AuditEntry $entry): array => [$entry->event, $entry->properties['assigned_to_user_id'], $entry->properties['previous_user_id']])->all())->toBe([
            ['ticket.assigned', $this->agent->id, null],
            ['ticket.assigned', $this->freeAgent->id, $this->agent->id],
            ['ticket.unassigned', null, $this->freeAgent->id],
        ])
        // Assignment is not a move: the status history is unchanged.
        ->and(ticketHistory($this->acme, $ticket))->toHaveCount(1);
});

it('changes category and priority, audited with what changed', function () {
    $ticket = raiseTicket($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
    ticketsDo($this->acme, fn (TicketService $s) => $s->changeDetails($this->agent, $ticket, ['category' => 'electrical', 'priority' => 'urgent']));

    $audit = AuditEntry::query()->where('event', 'ticket.updated')->sole();
    expect(storedTicket($this->acme, $ticket)->category->value)->toBe('electrical')
        ->and(storedTicket($this->acme, $ticket)->priority->value)->toBe('urgent')
        ->and($audit->properties['changed'])->toBe(['category', 'priority'])
        ->and($audit->properties['old'])->toEqual(['category' => 'plumbing', 'priority' => 'high'])
        ->and($audit->properties['new'])->toEqual(['category' => 'electrical', 'priority' => 'urgent']);

    expect(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->changeDetails($this->agent, $ticket, ['category' => 'roof', 'priority' => 'urgent'])))->toThrow(ValidationException::class);
});

it('unassigns the waiting tickets of a member who leaves, keeping who handled the closed ones', function () {
    $open = raiseTicket($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
    $closed = raiseTicket($this->acme, $this->owner, $this->flat4c, $this->tundeAtAcme);
    ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $open, $this->agent->id));
    ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $closed, $this->agent->id));
    forceTicketStatus($this->acme, $closed, 'closed', ['resolved_at' => now(), 'closed_at' => now()]);

    inOrganization($this->acme, fn () => app(Members::class)->remove($this->owner, Membership::query()->where('user_id', $this->agent->id)->sole()));

    expect(storedTicket($this->acme, $open)->assigned_to_user_id)->toBeNull()
        ->and(storedTicket($this->acme, $closed)->assigned_to_user_id)->toBe($this->agent->id)
        ->and(AuditEntry::query()->where('event', 'ticket.unassigned')->sole()->properties['reason'])->toBe('member_removed');
});
