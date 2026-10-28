<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Tickets\Tickets\TicketService;

require_once __DIR__.'/ticket-world.php';

/*
 * P04-01 (docs/plans/P04-subtasks.md default 4): each organization numbers
 * its tickets in one sequence, `TKT-000001` (App\Documents\DocumentNumberer,
 * type `ticket`, never restarting), taken when the ticket is sent: a draft
 * has none, so a discarded draft leaves no gap, and a raise that rolls back
 * gives its number to the next one. Concurrency: tests/Drill/
 * TicketNumberingDrillTest.php.
 */

beforeEach(function () {
    ticketWorld();
});

afterEach(fn () => ticketsFreshRequest());

it('numbers each organization\'s sent tickets in one sequence, giving a draft its number only when it is sent', function () {
    $first = raiseTicket($this->acme, $this->owner, $this->gatehouse);
    $draft = draftTicket($this->acme, $this->kemi, $this->lease);
    $discarded = draftTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Never mind']);
    ticketsDo($this->acme, fn (TicketService $s) => $s->discardDraft($this->kemi, $discarded));
    $foreign = raiseTicket($this->other, $this->otherOwner, $this->foreignUnit, $this->foreignTenant);
    $second = raiseTicket($this->acme, $this->agent, $this->flat4c, $this->tundeAtAcme);

    expect(storedTicket($this->acme, $draft)->number)->toBeNull();

    $sent = ticketsDo($this->acme, fn (TicketService $s) => $s->submit($this->kemi, $draft));
    $this->travelTo('2028-01-02 09:00:00');
    $nextYear = raiseTicket($this->acme, $this->owner, $this->oakUnit, $this->femi);

    expect([$first->number, $second->number, $sent->number, $nextYear->number])->toBe(['TKT-000001', 'TKT-000002', 'TKT-000003', 'TKT-000004'])
        ->and($foreign->number)->toBe('TKT-000001')
        ->and(storedTicket($this->acme, $discarded)->number)->toBeNull()
        ->and(DB::table('document_counters')->where('doc_type', 'ticket')->orderBy('organization_id')->get(['organization_id', 'year', 'last_seq'])
            ->map(fn (object $row): array => [(int) $row->organization_id, (int) $row->year, (int) $row->last_seq])->all())
        ->toBe([[$this->acme->id, 0, 4], [$this->other->id, 0, 1]]);
});

it('gives the number of a raise that rolled back to the next ticket', function () {
    raiseTicket($this->acme, $this->owner, $this->gatehouse);

    try {
        DB::transaction(function (): void {
            expect(raiseTicket($this->acme, $this->owner, $this->gatehouse, null, ['title' => 'Rolled back'])->number)->toBe('TKT-000002');
            throw new DomainException('The page failed after raising it.');
        });
    } catch (DomainException) {
    }

    expect(raiseTicket($this->acme, $this->owner, $this->gatehouse, null, ['title' => 'Kept'])->number)->toBe('TKT-000002')
        ->and(ticketsOf($this->acme)->pluck('title')->all())->toBe(['Kitchen sink leaking', 'Kept']);
});
