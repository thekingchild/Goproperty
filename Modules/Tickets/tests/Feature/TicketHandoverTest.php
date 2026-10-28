<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Media\EvidenceFileProtected;
use App\Media\Media;
use App\Tenancy\Moves\BuildingMove;
use App\Tenancy\Moves\BuildingMoveFailed;
use App\Tenancy\Moves\BuildingRecordMovers;
use App\Tenancy\Moves\MovesBuildingRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Handovers\Handovers\HandoverRefused;
use Modules\Tenants\Models\TenantProfile;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Models\TicketMessage;
use Modules\Tickets\Models\TicketNotice;
use Modules\Tickets\Tickets\TicketService;

require_once __DIR__.'/ticket-move-world.php';
require_once __DIR__.'/../../../Handovers/tests/Feature/return-world.php';

/*
 * P04-05 AC (IMPLEMENTATION_PLAN.md § 3; docs/plans/P04-subtasks.md
 * "Settled by the product owner", replacing P04-01 decision 10): accepting
 * a handover moves exactly the open tickets of the chosen buildings, in the
 * same transaction (a failure moves nothing); the receiving organization's
 * copy carries the tenant and lease records there, the public thread and
 * its files, never an internal note; the old ticket is closed with the
 * note and keeps its history; numbers stay gap-free in both organizations;
 * the tenant and the receiving owners and administrators are told once;
 * works for a return too (the end of a management, and the one-way
 * return); cross-organization probes. World: ticket-move-world.php.
 */

beforeEach(fn () => ticketMoveWorld());

afterEach(fn () => ticketsFreshRequest());

/** The landlord's tickets the handover moves, in the order they were sent (the draft last). */
function movingTickets(): array
{
    $test = test();

    return [$test->kemiOpen, $test->tundeResolved, $test->gatehouse, $test->musaReopened, $test->bisiOpen, $test->kemiDraft];
}

/** The tenant record standing for $tenant in the agency (by phone; the move copied it). */
function agencyTenantFor(TenantProfile $tenant): TenantProfile
{
    return inOrganization(test()->agency, fn (): TenantProfile => TenantProfile::query()->where('phone', $tenant->phone)->firstOrFail());
}

it('moves exactly the open tickets of the chosen buildings, as the receiving organization\'s own, and closes the ones left behind with the note', function () {
    $stays = [$this->kemiClosed, $this->cedarOpen];
    $staysBefore = array_map(fn (Ticket $ticket): array => storedTicket($this->landlord, $ticket)->getAttributes(), $stays);
    $historyBefore = array_map(fn (Ticket $ticket): array => ticketHistory($this->landlord, $ticket), movingTickets());
    $threadBefore = movedThread($this->landlord, $this->kemiOpen);

    acceptTicketHandover();

    expect(storedHandover($this->handover)->status)->toBe(HandoverStatus::Active);

    // Closed tickets and other buildings' tickets stay exactly as they were.
    expect(array_map(fn (Ticket $ticket): array => storedTicket($this->landlord, $ticket)->getAttributes(), $stays))->toEqual($staysBefore)
        ->and(movedCopy($this->agency, $this->kemiClosed))->toBeNull()
        ->and(movedCopy($this->agency, $this->cedarOpen))->toBeNull();

    // The agency has its own ticket for each open one, numbered after its own two, the draft unnumbered.
    $copies = array_map(fn (Ticket $ticket): Ticket => movedCopy($this->agency, $ticket) ?? throw new LogicException('Not moved: '.$ticket->title), movingTickets());
    expect(ticketsOf($this->agency)->map(fn (Ticket $ticket): array => [$ticket->number, $ticket->title, $ticket->status->value, $ticket->moved_from_number])->all())->toBe([
        ['TKT-000001', 'Lift stuck on 2nd floor', 'submitted', null],
        ['TKT-000002', 'Broken window', 'submitted', null],
        ['TKT-000003', 'Kitchen sink leaking', 'submitted', 'TKT-000001'],
        ['TKT-000004', 'Bathroom light flickers', 'resolved', 'TKT-000002'],
        ['TKT-000005', 'Gate motor noisy', 'submitted', 'TKT-000003'],
        ['TKT-000006', 'Water heater cold', 'submitted', 'TKT-000006'],
        ['TKT-000007', 'Window will not close', 'submitted', 'TKT-000007'],
        [null, 'Wardrobe door off its hinge', 'draft', null],
    ]);

    [$kemiCopy, $tundeCopy, $gateCopy, $musaCopy, $bisiCopy, $draftCopy] = $copies;
    $kemiThere = agencyTenantFor($this->kemi);

    // The moved tenant's record and the lease there; nobody assigned; where it came from.
    expect([$kemiCopy->tenant_profile_id, $kemiCopy->lease_id, $kemiCopy->unit_id, $kemiCopy->property_id])->toBe([$kemiThere->id, $this->kemiLease->id, $this->flat1->id, $this->palm->id])
        ->and($kemiThere->id)->not->toBe($this->kemi->id)
        ->and($kemiThere->user_id)->toBe($this->kemiUser->id)
        ->and([$tundeCopy->tenant_profile_id, $tundeCopy->lease_id])->toBe([agencyTenantFor($this->tunde)->id, $this->tundeLease->id])
        ->and([$gateCopy->tenant_profile_id, $gateCopy->lease_id])->toBe([null, null])
        ->and([$draftCopy->tenant_profile_id, $draftCopy->lease_id, $draftCopy->submitted_at])->toBe([$kemiThere->id, $this->kemiLease->id, null])
        ->and(array_map(fn (Ticket $copy): ?int => $copy->assigned_to_user_id, $copies))->each->toBeNull()
        ->and($kemiCopy->movedFromLine())->toBe('Moved from Eze Properties, was TKT-000001')
        ->and($draftCopy->movedFromLine())->toBe('Moved from Eze Properties')
        ->and([$kemiCopy->source->value, $kemiCopy->raised_by_user_id, $kemiCopy->category->value, $kemiCopy->priority->value, $kemiCopy->description])
        ->toBe([$this->kemiOpen->source->value, $this->kemiUser->id, $this->kemiOpen->category->value, $this->kemiOpen->priority->value, $this->kemiOpen->description])
        ->and($kemiCopy->submitted_at?->toDateTimeString())->toBe('2026-12-28 10:00:00');

    // Resolved stays resolved, from its original resolution; everything else open arrives submitted.
    expect($tundeCopy->resolved_at?->toDateTimeString())->toBe('2027-01-02 15:00:00')
        ->and([$musaCopy->reopen_count, $musaCopy->resolved_at, $musaCopy->reopened_at])->toBe([0, null, null])
        ->and(ticketHistory($this->agency, $kemiCopy))->toBe([[null, 'submitted', null, 'Moved from Eze Properties, was TKT-000001']])
        ->and(ticketHistory($this->agency, $tundeCopy))->toBe([[null, 'resolved', null, 'Moved from Eze Properties, was TKT-000002']])
        ->and(ticketHistory($this->agency, $draftCopy))->toBe([[null, 'draft', null, 'Moved from Eze Properties']]);

    // The tickets left behind: closed with the note, their history kept and one row added; the draft discarded.
    $statusesAtTheMove = ['in_progress', 'resolved', 'under_review', 'reopened', 'submitted'];

    foreach (array_slice(movingTickets(), 0, 5) as $i => $original) {
        $old = storedTicket($this->landlord, $original);
        expect($old->status)->toBe(TicketStatus::Closed)
            ->and($old->closed_at?->toDateTimeString())->toBe('2027-01-04 09:00:00')
            ->and($old->moved_out_at?->toDateTimeString())->toBe('2027-01-04 09:00:00')
            ->and($old->movedToLine())->toBe('Building moved to Adeyemi Realty Ltd on 4 Jan 2027')
            ->and($old->number)->toBe($original->number)
            ->and(ticketHistory($this->landlord, $original))->toBe([...$historyBefore[$i], [$statusesAtTheMove[$i], 'closed', null, 'Building moved to Adeyemi Realty Ltd on 4 Jan 2027']]);
    }

    expect(storedTicket($this->landlord, $this->kemiOpen)->assigned_to_user_id)->toBe($this->sade->id)
        ->and(storedTicket($this->landlord, $this->kemiDraft)->trashed())->toBeTrue()
        ->and(storedTicket($this->landlord, $this->kemiDraft)->moved_out_at)->not->toBeNull()
        ->and(movedThread($this->landlord, $this->kemiOpen))->toBe($threadBefore);

    // Audited on both sides, with the counts on the handover's move entry.
    $moveEntry = AuditEntry::query()->where('event', 'handover.records_moved_in')->latest('id')->firstOrFail()->properties?->toArray();
    expect($moveEntry['counts'])->toMatchArray(['tickets_moved' => 5, 'ticket_drafts_moved' => 1, 'ticket_messages_copied' => 2, 'ticket_files_copied' => 2])
        ->and(AuditEntry::query()->where('log_name', 'tickets')->where('event', 'ticket.moved_in')->count())->toBe(6)
        ->and(AuditEntry::query()->where('log_name', 'tickets')->where('event', 'ticket.moved_out')->count())->toBe(6)
        ->and(AuditEntry::query()->where('log_name', 'tickets')->where('event', 'ticket.thread_copied')->count())->toBe(1);
});

it('copies the public messages and their files as the receiving organization\'s evidence, never an internal note', function () {
    $originalFiles = movedFiles($this->landlord, $this->kemiOpen);

    acceptTicketHandover();

    $copy = movedCopy($this->agency, $this->kemiOpen);
    $thread = movedThread($this->agency, $copy);

    expect($thread)->toBe([
        ['tenant', $this->kemiUser->id, 'Here is a photo of the leak.', false, '2026-12-28 10:00:00', 1],
        ['staff', $this->ngozi->id, 'The plumber comes on Monday; his quote is attached.', false, '2026-12-29 11:00:00', 1],
    ])
        ->and(collect($thread)->pluck(2)->all())->not->toContain(INTERNAL_NOTE);

    // New files in the agency's folder, the same bytes; the originals stay with the landlord.
    $copied = movedFiles($this->agency, $copy);
    expect(array_map(fn (array $file): array => [$file[0], $file[1], $file[2]], $copied))->toBe([
        [$originalFiles[0][0], $originalFiles[0][1], $originalFiles[0][2]],
        [$originalFiles[1][0], $originalFiles[1][1], $originalFiles[1][2]],
    ])
        ->and(array_column($copied, 3))->each->toBe($this->agency->id)
        ->and(array_column($copied, 4))->each->toBeTrue()
        ->and(movedFiles($this->landlord, $this->kemiOpen))->toBe($originalFiles)
        ->and(array_column($originalFiles, 0))->toContain('internal-quote')
        ->and(array_column($copied, 0))->not->toContain('internal-quote')
        ->and(inOrganization($this->agency, fn () => Media::query()->where('name', 'internal-quote')->count()))->toBe(0)
        ->and(Storage::disk('media')->allFiles((string) $this->agency->id))->not->toBeEmpty();

    // The tenant and the staff there read the thread and open its files; the copies are evidence there too.
    $staffPage = ticketsPage($this->ada, '/'.$copy->id)->assertOk();
    expect(htmlOf($staffPage))->toContain('Moved from Eze Properties, was TKT-000001')
        ->toContain('Here is a photo of the leak.')
        ->not->toContain(INTERNAL_NOTE);

    $file = inOrganization($this->agency, fn (): Media => Media::query()->where('model_type', (new TicketMessage)->getMorphClass())->where('name', 'plumber-quote')->firstOrFail());
    ticketsFreshRequest();
    $this->actingAs($this->kemiUser)->get("/portal/tickets/{$copy->id}/files/{$file->id}")->assertRedirect();
    ticketsFreshRequest();
    $this->actingAs($this->ada)->get("/o/acme-homes/tickets/{$copy->id}/files/{$file->id}")->assertRedirect();
    expect(fn () => inOrganization($this->agency, fn () => Media::query()->findOrFail($file->id)->delete()))->toThrow(EvidenceFileProtected::class);

    // The agency's own conversation goes on from there.
    tenantReply($this->agency, $this->kemiUser, $copy, 'Thank you, I will be home on Monday.');
    expect(count(movedThread($this->agency, $copy)))->toBe(3);
});

it('keeps numbers gap-free in both organizations', function () {
    acceptTicketHandover();

    // Each side's next ticket takes the next number of its own sequence.
    $nextAgency = raiseTicket($this->agency, $this->ada, $this->office1, null, ['title' => 'Toilet blocked']);
    $nextLandlord = raiseTicket($this->landlord, $this->ngozi, $this->unitA, $this->femi, ['title' => 'Roof leak']);

    expect($nextAgency->number)->toBe('TKT-000008')
        ->and($nextLandlord->number)->toBe('TKT-000008')
        ->and(ticketsOf($this->agency)->pluck('number')->filter()->values()->all())->toBe(['TKT-000001', 'TKT-000002', 'TKT-000003', 'TKT-000004', 'TKT-000005', 'TKT-000006', 'TKT-000007', 'TKT-000008'])
        ->and(ticketsOf($this->landlord)->pluck('number')->filter()->sort()->values()->all())->toBe(['TKT-000001', 'TKT-000002', 'TKT-000003', 'TKT-000004', 'TKT-000005', 'TKT-000006', 'TKT-000007', 'TKT-000008']);

    // The moved draft gets the agency's next number when Kemi sends it there.
    $draft = movedCopy($this->agency, $this->kemiDraft);
    inOrganization($this->agency, fn () => app(TicketService::class)->updateDraft($this->kemiUser, $draft, $this->kemiLease->id, ticketInput(['title' => 'Wardrobe door off its hinge'])));
    expect(ticketsDo($this->agency, fn (TicketService $s) => $s->submit($this->kemiUser, storedTicket($this->agency, $draft)))->number)->toBe('TKT-000009');
});

it('tells the tenant and the receiving owners and administrators once per moved ticket, and nobody on the side it left', function () {
    $told = ticketMoveTold(fn () => acceptTicketHandover());

    // Kemi (portal account): her sent ticket only (her draft tells nobody); Tunde (portal account): his;
    // Musa (email, no account): by email; Bisi (phone only): nothing until P05. Ada and Seyi: each of the
    // five sent tickets once. Bola (agent), and Ngozi, Emeka and Sade on the side the tickets left: nothing.
    expect($told)->toBe([
        'ada.owner@example.com' => ['app', 'app', 'app', 'app', 'app', 'mail', 'mail', 'mail', 'mail', 'mail'],
        'kemi.adebayo@example.com' => ['app', 'mail'],
        'musa.danjuma@example.com' => ['mail'],
        'seyi.admin@example.com' => ['app', 'app', 'app', 'app', 'app', 'mail', 'mail', 'mail', 'mail', 'mail'],
        'tunde.bakare@example.com' => ['app', 'mail'],
    ]);

    // The notices are the agency's, keyed per ticket; the landlord's closings wrote none.
    $kemiCopy = movedCopy($this->agency, $this->kemiOpen);
    expect(inOrganization($this->agency, fn () => TicketNotice::query()->where('ticket_id', $kemiCopy->id)->orderBy('user_id')->get()->map(fn (TicketNotice $n): array => [$n->recipient, $n->side, $n->event])->all()))->toBe([
        ['user:'.$this->ada->id, 'staff', 'ticket.moved_in'],
        ['user:'.$this->seyi->id, 'staff', 'ticket.moved_in'],
        ['user:'.$this->kemiUser->id, 'tenant', 'ticket.moved_in'],
    ])
        ->and(inOrganization($this->landlord, fn () => TicketNotice::query()->where('event', 'ticket.closed')->whereIn('ticket_id', array_map(fn (Ticket $t): int => $t->id, movingTickets()))->count()))->toBe(0);

    // The tenant's words.
    $mail = collect(app('mailer')->getSymfonyTransport()->messages())->first(fn ($m): bool => $m->getOriginalMessage()->getTo()[0]->getAddress() === 'kemi.adebayo@example.com');
    expect($mail)->not->toBeNull()
        ->and((string) $mail->getOriginalMessage()->getSubject())->toBe('Your repair request is now handled by Adeyemi Realty Ltd')
        ->and((string) $mail->getOriginalMessage()->getTextBody().(string) $mail->getOriginalMessage()->getHtmlBody())->toContain('It was TKT-000001 with Eze Properties; its number is now TKT-000003.');
});

it('lets a moved resolved ticket be confirmed or reopened there within the window counted from its first resolution, and never the one left behind', function () {
    acceptTicketHandover();
    $copy = movedCopy($this->agency, $this->tundeResolved);

    // Resolved on 2 January: the agency's window (14 days) runs to 16 January.
    expect(inOrganization($this->agency, fn () => app(TicketService::class)->reopenableUntil($copy))?->toDateTimeString())->toBe('2027-01-16 15:00:00');

    // The ticket left behind cannot move again, by staff or by the tenant, even within the window. (Tunde's
    // record there archived itself once he owed nothing, P04-04, so the portal no longer reaches it at all.)
    $old = storedTicket($this->landlord, $this->tundeResolved);
    expect(inOrganization($this->landlord, fn () => app(TicketService::class)->reopenableUntil($old)))->toBeNull()
        ->and(fn () => ticketsDo($this->landlord, fn (TicketService $s) => $s->reopen($this->ngozi, $old, 'Still flickers.')))->toThrow(ValidationException::class, 'closed when its building moved to Adeyemi Realty Ltd on 4 Jan 2027. It goes on there.')
        ->and(fn () => ticketsDo($this->landlord, fn (TicketService $s) => $s->reopenAsTenant($this->tundeUser, $old, 'Still flickers.')))->toThrow(AuthorizationException::class)
        ->and(storedTicket($this->landlord, $old)->status)->toBe(TicketStatus::Closed);

    $page = htmlOf(ticketsPage($this->ngozi, '/'.$old->id, 'eze-properties')->assertOk());
    expect($page)->toContain('Building moved to Adeyemi Realty Ltd on 4 Jan 2027')->not->toContain('wire:click="reopen"');

    // There, past the window (counted from 2 January), Tunde can no longer reopen it; within it, he can.
    $this->travelTo('2027-01-17 09:00:00');
    expect(fn () => ticketsDo($this->agency, fn (TicketService $s) => $s->reopenAsTenant($this->tundeUser, storedTicket($this->agency, $copy), 'Still flickers.')))->toThrow(ValidationException::class, 'could be reopened until 16 Jan 2027');

    $this->travelTo('2027-01-10 09:00:00');
    expect(htmlOf(repairsPage($this->tundeUser, '/'.$copy->id)->assertOk()))->toContain('Moved from Eze Properties, was TKT-000002');
    ticketsDo($this->agency, fn (TicketService $s) => $s->reopenAsTenant($this->tundeUser, storedTicket($this->agency, $copy), 'Still flickers.'));
    expect(storedTicket($this->agency, $copy)->status)->toBe(TicketStatus::Reopened)
        ->and(storedTicket($this->agency, $copy)->resolved_at?->toDateTimeString())->toBe('2027-01-02 15:00:00');
});

/** A mover that fails at a chosen point of the move. */
final class TicketMoveFailingMover implements MovesBuildingRecords
{
    public static int $order = 95;

    public function order(): int
    {
        return self::$order;
    }

    public function move(BuildingMove $move): void
    {
        throw new BuildingMoveFailed('A module could not move its records.');
    }
}

it('moves nothing when the acceptance fails, whether before, during or after the tickets move', function (string $where) {
    if ($where === 'during the tickets (a file cannot be read)') {
        // Kemi's photo copies first, then Ngozi's quote cannot be read: the copied photo must go too.
        $quote = inOrganization($this->landlord, fn (): Media => Media::query()->where('name', 'plumber-quote')->firstOrFail());
        Storage::disk('media')->delete($quote->getPathRelativeToRoot());
    } else {
        TicketMoveFailingMover::$order = $where === 'before the tickets' ? 45 : 95;
        app(BuildingRecordMovers::class)->register(TicketMoveFailingMover::class);
    }

    $this->handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);
    $tables = ['tickets', 'ticket_status_history', 'ticket_messages', 'ticket_notices', 'media', 'document_counters', 'tenant_profiles', 'leases', 'properties'];
    $before = rowsByOrganization($tables);
    $tickets = DB::table('tickets')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    $landlordFiles = Storage::disk('media')->allFiles((string) $this->landlord->id);
    $audits = AuditEntry::query()->count();

    $told = ticketMoveTold(fn () => expect(fn () => acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $this->handover)))->toThrow(HandoverRefused::class, 'could not be moved, so nothing changed'));

    expect(rowsByOrganization($tables))->toEqual($before)
        ->and(DB::table('tickets')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all())->toEqual($tickets)
        ->and(Storage::disk('media')->allFiles((string) $this->agency->id))->toBe([])
        ->and(Storage::disk('media')->allFiles((string) $this->landlord->id))->toBe($landlordFiles)
        ->and(AuditEntry::query()->count())->toBe($audits)
        ->and(storedHandover($this->handover)->status)->toBe(HandoverStatus::Pending)
        ->and($told)->toBe([]);
})->with([
    'before the tickets' => ['before the tickets'],
    'during the tickets (a file cannot be read)' => ['during the tickets (a file cannot be read)'],
    'after the tickets and files moved' => ['after the tickets and files moved'],
]);

it('moves open tickets back as new ones when the management ends, onto the tenants\' original records (there and back)', function () {
    acceptTicketHandover();
    $kemiCopy = movedCopy($this->agency, $this->kemiOpen);
    $gateCopy = movedCopy($this->agency, $this->gatehouse);

    // At the agency: work goes on, an internal note and a reply; the gatehouse ticket is closed there.
    ticketsDo($this->agency, fn (TicketService $s) => $s->startWork($this->ada, storedTicket($this->agency, $kemiCopy)));
    staffReply($this->agency, $this->ada, storedTicket($this->agency, $kemiCopy), 'Our plumber will come instead.');
    staffReply($this->agency, $this->ada, storedTicket($this->agency, $kemiCopy), 'AGENCY-ONLY: bill the landlord', internal: true);
    ticketsDo($this->agency, fn (TicketService $s) => $s->resolve($this->ada, storedTicket($this->agency, $gateCopy)));
    ticketsDo($this->agency, fn (TicketService $s) => $s->close($this->ada, storedTicket($this->agency, $gateCopy)));

    // Ngozi gives notice; it ends 30 days later through the hourly job.
    giveHandoverNotice($this->ngozi, $this->landlord, storedHandover($this->handover));
    $this->travelTo('2027-02-03 10:00:00');
    $told = ticketMoveTold(fn () => runHandoverJob('handovers-end'));
    expect(storedHandover($this->handover)->status)->toBe(HandoverStatus::Ended);

    // Kemi's ticket is a new landlord ticket, numbered after the landlord's seven, on her original record.
    $back = movedCopy($this->landlord, $kemiCopy);
    expect($back)->not->toBeNull()
        ->and([$back->number, $back->status, $back->tenant_profile_id, $back->lease_id, $back->movedFromLine()])
        ->toBe(['TKT-000008', TicketStatus::Submitted, $this->kemi->id, $this->kemiLease->id, 'Moved from Adeyemi Realty Ltd, was TKT-000003'])
        ->and(movedThread($this->landlord, $back))->toBe([
            ['tenant', $this->kemiUser->id, 'Here is a photo of the leak.', false, '2026-12-28 10:00:00', 1],
            ['staff', $this->ngozi->id, 'The plumber comes on Monday; his quote is attached.', false, '2026-12-29 11:00:00', 1],
            ['staff', $this->ada->id, 'Our plumber will come instead.', false, '2027-01-04 09:00:00', 0],
        ]);

    // The landlord's first ticket stays closed as it was; the agency's copy closes with the note.
    expect(storedTicket($this->landlord, $this->kemiOpen)->status)->toBe(TicketStatus::Closed)
        ->and(storedTicket($this->landlord, $this->kemiOpen)->movedToLine())->toBe('Building moved to Adeyemi Realty Ltd on 4 Jan 2027')
        ->and(storedTicket($this->agency, $kemiCopy)->status)->toBe(TicketStatus::Closed)
        ->and(storedTicket($this->agency, $kemiCopy)->movedToLine())->toBe('Building moved to Eze Properties on 3 Feb 2027')
        ->and(ticketHistory($this->agency, $kemiCopy))->toBe([
            [null, 'submitted', null, 'Moved from Eze Properties, was TKT-000001'],
            ['submitted', 'in_progress', $this->ada->id, null],
            ['in_progress', 'closed', null, 'Building moved to Eze Properties on 3 Feb 2027'],
        ]);

    // The gatehouse ticket closed at the agency stays there; the others come back, numbered in order.
    expect(movedCopy($this->landlord, $gateCopy))->toBeNull()
        ->and(ticketsOf($this->landlord)->pluck('number')->filter()->sort()->values()->all())
        ->toBe(['TKT-000001', 'TKT-000002', 'TKT-000003', 'TKT-000004', 'TKT-000005', 'TKT-000006', 'TKT-000007', 'TKT-000008', 'TKT-000009', 'TKT-000010', 'TKT-000011']);

    // Kemi works on it at the landlord's again: the ticket behaves as any other.
    tenantReply($this->landlord, $this->kemiUser, $back, 'Glad you are back.');
    ticketsDo($this->landlord, fn (TicketService $s) => $s->resolve($this->ngozi, storedTicket($this->landlord, $back)));
    ticketsDo($this->landlord, fn (TicketService $s) => $s->confirmResolution($this->kemiUser, storedTicket($this->landlord, $back)));
    expect(storedTicket($this->landlord, $back)->status)->toBe(TicketStatus::Closed);

    // Told on the landlord's side: Kemi, Tunde, Musa once each; Ngozi and Emeka once per ticket (4 sent ones).
    expect(array_keys($told))->toBe(['emeka.eze@example.com', 'kemi.adebayo@example.com', 'musa.danjuma@example.com', 'ngozi.eze@example.com', 'tunde.bakare@example.com'])
        ->and(count($told['ngozi.eze@example.com']))->toBe(8)
        ->and($told['kemi.adebayo@example.com'])->toBe(['app', 'mail']);
});

it('moves open tickets on a one-way return to the landlord\'s own organization', function () {
    // The agency holds Harbour Point without a handover (the return world of P04-04, built here on the tickets' world).
    $this->ngoziAtAgency = ownerIn($this->agency, ['type' => 'person', 'first_name' => 'Ngozi', 'last_name' => 'Eze', 'email' => 'ngozi.eze@example.com', 'phone' => '+2348051112222']);
    linkOwner($this->agency, $this->ngoziAtAgency, $this->ngozi);
    $harbour = buildingIn($this->agency, ['name' => 'Harbour Point', 'slug' => 'harbour-point', 'address' => '5 Marina Road, Lagos Island', 'owner_profile_id' => $this->ngoziAtAgency->id]);
    $flatA = unitIn($this->agency, $harbour, ['unit_label' => 'Flat A']);
    $yemi = tenantIn($this->agency, ['first_name' => 'Yemi', 'last_name' => 'Ola', 'phone' => '+2348038889999', 'email' => 'yemi.ola@example.com']);
    $yemiLease = activeLease($this->agency, $this->ada, $flatA, $yemi, ['rent' => '1,000,000']);
    $open = raiseTicket($this->agency, $this->ada, $flatA, $yemi, ['title' => 'Shower drain slow']);
    $staffOnly = staffReply($this->agency, $this->ada, $open, 'AGENCY-ONLY: landlord pays this one', internal: true);
    $officeTicket = $this->agencyOwn[0];

    $return = offerReturn($this->ada, $this->agency, $this->landlord, [$harbour]);
    $told = ticketMoveTold(fn () => acceptHandover($this->ngozi, $this->landlord, incomingHandover($this->landlord, $return)));

    expect(storedHandover($return)->status)->toBe(HandoverStatus::Returned);

    $copy = movedCopy($this->landlord, $open);
    $yemiThere = inOrganization($this->landlord, fn (): TenantProfile => TenantProfile::query()->where('phone', '+2348038889999')->firstOrFail());
    expect([$copy->number, $copy->status, $copy->tenant_profile_id, $copy->lease_id, $copy->movedFromLine()])
        ->toBe(['TKT-000008', TicketStatus::Submitted, $yemiThere->id, $yemiLease->id, 'Moved from Adeyemi Realty Ltd, was TKT-000003'])
        ->and(movedThread($this->landlord, $copy))->toBe([])
        ->and(storedTicket($this->agency, $open)->status)->toBe(TicketStatus::Closed)
        ->and(storedTicket($this->agency, $open)->movedToLine())->toBe('Building moved to Eze Properties on 4 Jan 2027')
        ->and(storedTicket($this->agency, $officeTicket)->status)->toBe(TicketStatus::Submitted)
        ->and(movedCopy($this->landlord, $officeTicket))->toBeNull()
        ->and(threadOf($this->agency, $open))->toBe([['staff', 'AGENCY-ONLY: landlord pays this one', true]])
        ->and($staffOnly->exists)->toBeTrue();

    // Yemi and the landlord's owner and administrator, once each.
    expect($told)->toBe([
        'emeka.eze@example.com' => ['app', 'mail'],
        'ngozi.eze@example.com' => ['app', 'mail'],
        'yemi.ola@example.com' => ['mail'],
    ]);
});

it('keeps the moved tickets of each organization out of the other\'s reach (cross-organization probes)', function () {
    acceptTicketHandover();
    $copy = movedCopy($this->agency, $this->kemiOpen);
    $old = storedTicket($this->landlord, $this->kemiOpen);
    $oldFile = inOrganization($this->landlord, fn (): Media => Media::query()->where('name', 'leak')->firstOrFail());
    $newFile = inOrganization($this->agency, fn (): Media => Media::query()->where('name', 'leak')->firstOrFail());

    expect($newFile->id)->not->toBe($oldFile->id)
        ->and($newFile->uuid)->not->toBe($oldFile->uuid);

    // Each organization's scope sees only its own ticket.
    expect(inOrganization($this->agency, fn () => Ticket::query()->withTrashed()->find($old->id)))->toBeNull()
        ->and(inOrganization($this->landlord, fn () => Ticket::query()->withTrashed()->find($copy->id)))->toBeNull()
        ->and(inOrganization($this->agency, fn () => TicketMessage::query()->where('ticket_id', $old->id)->count()))->toBe(0);

    // Pages: each side's ticket is 404 to the other side.
    ticketsPage($this->ada, '/'.$old->id)->assertNotFound();
    ticketsPage($this->ngozi, '/'.$copy->id, 'eze-properties')->assertNotFound();
    ticketsPage($this->sade, '/'.$copy->id, 'eze-properties')->assertNotFound();

    // Files: through a ticket of the reader's organization only, and only that ticket's own.
    ticketsFreshRequest();
    $this->actingAs($this->ada)->get("/o/acme-homes/tickets/{$copy->id}/files/{$oldFile->id}")->assertNotFound();
    ticketsFreshRequest();
    $this->actingAs($this->ngozi)->get("/o/eze-properties/tickets/{$old->id}/files/{$newFile->id}")->assertNotFound();
    ticketsFreshRequest();
    $this->actingAs($this->kemiUser)->get("/portal/tickets/{$copy->id}/files/{$oldFile->id}")->assertNotFound();
    // Kemi's record left behind archived itself (P04-04: nothing owed there), so the old ticket is out of her reach too.
    ticketsFreshRequest();
    $this->actingAs($this->kemiUser)->get("/portal/tickets/{$old->id}/files/{$oldFile->id}")->assertNotFound();
    expect(inOrganization($this->landlord, fn () => TenantProfile::query()->withTrashed()->findOrFail($this->kemi->id)->trashed()))->toBeTrue();

    // Another tenant, and an agent of another building, do not reach the copy.
    ticketsFreshRequest();
    $this->actingAs($this->tundeUser)->get('/portal/tickets/'.$copy->id)->assertNotFound();
    ticketsPage($this->bola, '/'.$copy->id)->assertNotFound();

    // Kemi follows the new one, in the agency's colours; the landlord's staff still read the old one, with where it went.
    repairsPage($this->kemiUser, '/'.$old->id)->assertNotFound();
    expect(htmlOf(repairsPage($this->kemiUser, '/'.$copy->id)->assertOk()))->toContain('Moved from Eze Properties, was TKT-000001')
        ->and(htmlOf(ticketsPage($this->emeka, '/'.$old->id, 'eze-properties')->assertOk()))->toContain('Building moved to Adeyemi Realty Ltd on 4 Jan 2027');
});

it('moves nothing of tickets when the moved buildings have no open ticket', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->cedar]);
    ticketsDo($this->landlord, fn (TicketService $s) => $s->resolve($this->ngozi, $this->cedarOpen));
    ticketsDo($this->landlord, fn (TicketService $s) => $s->close($this->ngozi, storedTicket($this->landlord, $this->cedarOpen)));
    $before = rowsByOrganization(['tickets', 'ticket_status_history', 'ticket_messages']);

    acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $handover));

    expect(rowsByOrganization(['tickets', 'ticket_status_history', 'ticket_messages']))->toEqual($before)
        ->and(storedTicket($this->landlord, $this->cedarOpen)->moved_out_at)->toBeNull()
        ->and(AuditEntry::query()->where('event', 'handover.records_moved_in')->latest('id')->firstOrFail()->properties?->toArray()['counts'] ?? [])->not->toHaveKey('tickets_moved');
});

it('lets no staff page or service reopen, assign or answer a ticket left behind', function () {
    acceptTicketHandover();
    $old = storedTicket($this->landlord, $this->kemiOpen);

    expect(fn () => staffReply($this->landlord, $this->ngozi, $old, 'Hello?'))->toThrow(ValidationException::class, 'closed')
        ->and(fn () => tenantReply($this->landlord, $this->kemiUser, $old, 'Hello?'))->toThrow(AuthorizationException::class)
        ->and(fn () => ticketsDo($this->landlord, fn (TicketService $s) => $s->assign($this->ngozi, $old, null)))->toThrow(ValidationException::class)
        ->and(fn () => inOrganization($this->landlord, fn () => app(TicketService::class)->closeMovedOut($old, 'Elsewhere', now()->toImmutable(), 1)))->toThrow(ValidationException::class, 'closed already');
});

it('does not move tickets while the handover is only offered, declined or withdrawn', function () {
    $before = rowsByOrganization(['tickets', 'ticket_status_history', 'ticket_messages', 'media']);

    $offer = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    declineHandover($this->ada, $this->agency, incomingHandover($this->agency, $offer));
    $again = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    withdrawHandover($this->ngozi, $this->landlord, storedHandover($again));

    expect(rowsByOrganization(['tickets', 'ticket_status_history', 'ticket_messages', 'media']))->toEqual($before)
        ->and(storedTicket($this->landlord, $this->kemiOpen)->status)->toBe(TicketStatus::InProgress);
});
