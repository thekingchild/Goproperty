<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Members\Members;
use App\Models\Membership;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Properties\Models\AgentAssignment;
use Modules\Tickets\Events\TicketAssignmentChanged;
use Modules\Tickets\Events\TicketStatusChanged;
use Modules\Tickets\Listeners\TellTicketParties;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Models\TicketNotice;
use Modules\Tickets\Notifications\TicketUpdate;
use Modules\Tickets\Tickets\TicketService;
use Symfony\Component\Mime\Address;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/ticket-world.php';
require_once __DIR__.'/notices.php';

/*
 * P04-03 acceptance "each transition notifies the right party once"
 * (ARCHITECTURE.md § 6.6; docs/plans/P04-subtasks.md default 9): every
 * move and assignment tells, in the app and by email, the ticket's tenant
 * (unless they made it) and the staff side — the assigned member, or the
 * owners and administrators when nobody is assigned — each exactly once,
 * and never the person who acted. Phone-only tenants hear nothing until
 * P05. The real pipeline runs (queued listener and notifications on the
 * sync queue, the `notifications` table, the array mailer), so "once" is
 * counted where the person would see it.
 *
 * World (ticket-world.php): Ada the owner, Seyi an administrator, Bola an
 * agent of Palm Court only, Chika an agent of every building; Kemi
 * (portal account) at Flat 3B; Femi (email, no portal account) at Oak
 * Lodge; Bello Homes' Chidi elsewhere.
 */

beforeEach(function () {
    ticketWorld();
});

afterEach(fn () => ticketsFreshRequest());

/** A ticket of Kemi's, sent, and assigned to $assignee when given. */
function noticeKemiTicket(?User $assignee = null): Ticket
{
    $ticket = tenantTicket(test()->acme, test()->kemi, test()->lease);

    if ($assignee !== null) {
        ticketsDo(test()->acme, fn (TicketService $s) => $s->assign(test()->owner, $ticket, $assignee->id));
    }

    return $ticket;
}

/** Kemi's ticket, resolved by the owner (and assigned to $assignee). */
function noticeKemiResolved(?User $assignee = null): Ticket
{
    $ticket = noticeKemiTicket($assignee);
    ticketsDo(test()->acme, fn (TicketService $s) => $s->resolve(test()->owner, $ticket, 'Washer replaced.'));

    return $ticket;
}

describe('acceptance: each transition notifies the right party once', function () {
    it('tells each party exactly once for every transition and assignment, and never the person who acted', function (string $case, array $expected) {
        $action = match ($case) {
            'staff raise a ticket for a tenant (unassigned)' => fn () => raiseTicket($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme),
            'an agent raises a ticket for a unit with no tenant' => fn () => raiseTicket($this->acme, $this->agent, $this->gatehouse),
            'staff raise a ticket already assigned' => fn () => raiseTicket($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme, ['assigneeId' => $this->agent->id]),
            'an agent raises a ticket and assigns it to themselves' => fn () => raiseTicket($this->acme, $this->agent, $this->flat3b, $this->kemiAtAcme, ['assigneeId' => $this->agent->id]),
            'staff raise a ticket for a tenant with no portal account' => fn () => raiseTicket($this->acme, $this->freeAgent, $this->oakUnit, $this->femi),
            'the tenant sends their ticket' => fn () => tenantTicket($this->acme, $this->kemi, $this->lease),
            'the owner starts the review of a ticket assigned to Chika' => (function () {
                $ticket = noticeKemiTicket($this->freeAgent);

                return fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->startReview($this->owner, $ticket));
            })(),
            'the assignee starts work themselves' => (function () {
                $ticket = noticeKemiTicket($this->freeAgent);

                return fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->startWork($this->freeAgent, $ticket));
            })(),
            'the owner resolves an unassigned ticket' => (function () {
                $ticket = noticeKemiTicket();

                return fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->owner, $ticket, 'Washer replaced.'));
            })(),
            'the tenant confirms the fix of a ticket assigned to Bola' => (function () {
                $ticket = noticeKemiResolved($this->agent);

                return fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->confirmResolution($this->kemi, $ticket));
            })(),
            'the tenant reopens an unassigned ticket' => (function () {
                $ticket = noticeKemiResolved();

                return fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->reopenAsTenant($this->kemi, $ticket, 'Still dripping'));
            })(),
            'the administrator closes a resolved ticket assigned to Bola' => (function () {
                $ticket = noticeKemiResolved($this->agent);

                return fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->close($this->admin, $ticket, 'Kemi confirmed by phone.'));
            })(),
            'the owner reopens a ticket assigned to the administrator' => (function () {
                $ticket = noticeKemiResolved($this->admin);

                return fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->reopen($this->owner, $ticket, 'The plumber left a loose pipe.'));
            })(),
            'the auto-close closes a ticket assigned to Bola' => (function () {
                $ticket = noticeKemiResolved($this->agent);
                $this->travel(8)->days();

                return fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->autoClose($ticket->id));
            })(),
            'the auto-close closes an unassigned ticket' => (function () {
                $ticket = noticeKemiResolved();
                $this->travel(8)->days();

                return fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->autoClose($ticket->id));
            })(),
            'the owner assigns a ticket to Bola' => (function () {
                $ticket = noticeKemiTicket();

                return fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $ticket, $this->agent->id));
            })(),
            'an agent takes a ticket themselves' => (function () {
                $ticket = noticeKemiTicket();

                return fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->freeAgent, $ticket, $this->freeAgent->id));
            })(),
            'the administrator gives Bola\'s ticket to Chika' => (function () {
                $ticket = noticeKemiTicket($this->agent);

                return fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->admin, $ticket, $this->freeAgent->id));
            })(),
            'the owner takes a ticket off Bola' => (function () {
                $ticket = noticeKemiTicket($this->agent);

                return fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $ticket, null));
            })(),
            'Bola leaves the organization holding a ticket' => (function () {
                noticeKemiTicket($this->agent);

                return fn () => inOrganization($this->acme, fn () => app(Members::class)->remove($this->owner, Membership::query()->where('user_id', $this->agent->id)->sole()));
            })(),
            'the tenant drafts, changes and discards a ticket' => fn () => ticketsDo($this->acme, function (TicketService $s) {
                $draft = $s->draftAsTenant($this->kemi, $this->lease->id, ticketInput());
                $s->updateDraft($this->kemi, $draft, $this->lease->id, ticketInput(['title' => 'Changed my mind']));
                $s->discardDraft($this->kemi, $draft);
            }),
        };

        expect(ticketToldBy($action))->toEqual($expected)
            // Chidi works for another organization: never told about Acme's tickets.
            ->and(DB::table('notifications')->where('notifiable_id', $this->otherOwner->id)->count())->toBe(0);
    })->with([
        ['staff raise a ticket for a tenant (unassigned)', [TN_KEMI => ['app:ticket.raised', 'mail'], TN_SEYI => ['app:ticket.raised', 'mail']]],
        ['an agent raises a ticket for a unit with no tenant', [TN_ADA => ['app:ticket.raised', 'mail'], TN_SEYI => ['app:ticket.raised', 'mail']]],
        ['staff raise a ticket already assigned', [TN_BOLA => ['app:ticket.assigned', 'mail'], TN_KEMI => ['app:ticket.raised', 'mail']]],
        ['an agent raises a ticket and assigns it to themselves', [TN_KEMI => ['app:ticket.raised', 'mail']]],
        ['staff raise a ticket for a tenant with no portal account', [TN_ADA => ['app:ticket.raised', 'mail'], TN_FEMI => ['mail'], TN_SEYI => ['app:ticket.raised', 'mail']]],
        ['the tenant sends their ticket', [TN_ADA => ['app:ticket.raised', 'mail'], TN_SEYI => ['app:ticket.raised', 'mail']]],
        ['the owner starts the review of a ticket assigned to Chika', [TN_CHIKA => ['app:ticket.under_review', 'mail'], TN_KEMI => ['app:ticket.under_review', 'mail']]],
        ['the assignee starts work themselves', [TN_KEMI => ['app:ticket.in_progress', 'mail']]],
        ['the owner resolves an unassigned ticket', [TN_KEMI => ['app:ticket.resolved', 'mail'], TN_SEYI => ['app:ticket.resolved', 'mail']]],
        ['the tenant confirms the fix of a ticket assigned to Bola', [TN_BOLA => ['app:ticket.closed', 'mail']]],
        ['the tenant reopens an unassigned ticket', [TN_ADA => ['app:ticket.reopened', 'mail'], TN_SEYI => ['app:ticket.reopened', 'mail']]],
        ['the administrator closes a resolved ticket assigned to Bola', [TN_BOLA => ['app:ticket.closed', 'mail'], TN_KEMI => ['app:ticket.closed', 'mail']]],
        ['the owner reopens a ticket assigned to the administrator', [TN_KEMI => ['app:ticket.reopened', 'mail'], TN_SEYI => ['app:ticket.reopened', 'mail']]],
        ['the auto-close closes a ticket assigned to Bola', [TN_BOLA => ['app:ticket.closed', 'mail'], TN_KEMI => ['app:ticket.closed', 'mail']]],
        ['the auto-close closes an unassigned ticket', [TN_ADA => ['app:ticket.closed', 'mail'], TN_KEMI => ['app:ticket.closed', 'mail'], TN_SEYI => ['app:ticket.closed', 'mail']]],
        ['the owner assigns a ticket to Bola', [TN_BOLA => ['app:ticket.assigned', 'mail']]],
        ['an agent takes a ticket themselves', []],
        ['the administrator gives Bola\'s ticket to Chika', [TN_CHIKA => ['app:ticket.assigned', 'mail']]],
        ['the owner takes a ticket off Bola', []],
        ['Bola leaves the organization holding a ticket', []],
        ['the tenant drafts, changes and discards a ticket', []],
    ]);

    it('keeps one notice row per party and announcement, and the tenant\'s and the staff\'s links go to their own pages', function () {
        $ticket = raiseTicket($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
        ticketsDo($this->acme, fn (TicketService $s) => $s->startWork($this->owner, $ticket));

        $notices = inOrganization($this->acme, fn () => TicketNotice::query()->orderBy('id')->get());
        $raised = fn (User $user): array => DB::table('notifications')->where('notifiable_id', $user->id)->get()
            ->map(fn (object $row): array => json_decode((string) $row->data, true))->firstWhere('event', 'ticket.raised');
        $kemi = $raised($this->kemi);
        $seyi = $raised($this->admin);

        expect($notices->map(fn (TicketNotice $notice): array => [$notice->recipient, $notice->side, $notice->event, $notice->channels])->all())->toEqual([
            ['user:'.$this->kemi->id, 'tenant', 'ticket.raised', ['database', 'mail']],
            ['user:'.$this->admin->id, 'staff', 'ticket.raised', ['database', 'mail']],
            ['user:'.$this->kemi->id, 'tenant', 'ticket.in_progress', ['database', 'mail']],
            ['user:'.$this->admin->id, 'staff', 'ticket.in_progress', ['database', 'mail']],
        ])
            ->and($notices->every(fn (TicketNotice $notice): bool => $notice->mailed_at !== null))->toBeTrue()
            ->and(DB::table('notifications')->pluck('id')->sort()->values()->all())->toEqual($notices->pluck('notification_id')->sort()->values()->all())
            ->and($kemi['url'])->toBe(route('portal.tickets.show', $ticket->id))
            ->and($kemi['number'])->toBe('TKT-000001')
            ->and($kemi['message'])->toBe('Adeyemi Realty Ltd reported a repair for you: TKT-000001')
            ->and($seyi['url'])->toBe(route('workspace.tickets.show', ['organization' => 'acme-homes', 'ticket' => $ticket->id]))
            ->and($seyi['message'])->toBe('New ticket TKT-000001: Kitchen sink leaking');
    });
});

it('acceptance: tells a phone-only tenant nothing until WhatsApp and SMS come (P05), in the app or by email', function () {
    $phoneOnly = User::factory()->create(['name' => 'Musa Bello', 'email' => null, 'phone' => '+2348037778888']);
    $musa = tenantIn($this->acme, ['first_name' => 'Musa', 'last_name' => 'Bello', 'phone' => '+2348037778888', 'email' => null, 'user_id' => $phoneOnly->id]);
    $lease = activeLease($this->acme, $this->owner, $this->gatehouse, $musa, ['rent' => '1,500,000']);
    $ticket = tenantTicket($this->acme, $phoneOnly, $lease);

    $told = ticketToldBy(fn () => ticketsDo($this->acme, function (TicketService $s) use ($ticket) {
        $s->startWork($this->owner, $ticket);
        $s->resolve($this->owner, $ticket, 'Fixed.');
    }));

    expect($told)->toEqual([TN_SEYI => ['app:ticket.in_progress', 'app:ticket.resolved', 'mail', 'mail']])
        ->and(DB::table('notifications')->where('notifiable_id', $phoneOnly->id)->count())->toBe(0)
        ->and(inOrganization($this->acme, fn () => TicketNotice::query()->where('side', 'tenant')->count()))->toBe(0);
});

it('acceptance: tells a tenant with no portal account by email, with no link to a page they cannot open', function () {
    $ticket = raiseTicket($this->acme, $this->owner, $this->oakUnit, $this->femi);
    app('mailer')->getSymfonyTransport()->flush();

    ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->owner, $ticket, 'Pump replaced.'));

    $mail = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($message) => $message->getOriginalMessage())
        ->first(fn ($message): bool => $message->getTo()[0]->getAddress() === TN_FEMI);

    expect($mail)->not->toBeNull()
        ->and($mail->getSubject())->toBe('TKT-000001 is fixed: please check')
        ->and($mail->getHtmlBody())->toContain('Adeyemi Realty Ltd says your repair')->toContain('Note: Pump replaced.')->toContain('reopen it within 14 days')
        ->not->toContain('/portal/tickets')
        ->and(inOrganization($this->acme, fn () => TicketNotice::query()->where('side', 'tenant')->where('event', 'ticket.resolved')->sole()->recipient))->toBe('tenant:'.$this->femi->id);
});

it('sends the emails in the agency\'s name: its sender name, replies to its email, its name as the signature', function () {
    $this->acme->forceFill(['branding' => ['trading_name' => 'Adeyemi Realty', 'email' => 'repairs@adeyemi.example', 'email_sender_name' => 'Adeyemi Realty Repairs']])->save();
    $ticket = noticeKemiTicket();
    app('mailer')->getSymfonyTransport()->flush();

    ticketsDo($this->acme, fn (TicketService $s) => $s->startReview($this->admin, $ticket));

    $mail = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($message) => $message->getOriginalMessage())
        ->first(fn ($message): bool => $message->getTo()[0]->getAddress() === TN_KEMI);

    expect($mail->getFrom()[0]->getName())->toBe('Adeyemi Realty Repairs')
        ->and(array_map(fn (Address $address): string => $address->getAddress(), $mail->getReplyTo()))->toBe(['repairs@adeyemi.example'])
        ->and($mail->getSubject())->toBe('TKT-000001: Adeyemi Realty is looking into it')
        ->and($mail->getHtmlBody())->toContain('Hello Kemi Adebayo,')->toContain('Adeyemi Realty is looking into your repair')->toContain(route('portal.tickets.show', $ticket->id));

    // Without a sender name of its own: "{agency} via {product}".
    $this->acme->forceFill(['branding' => ['trading_name' => 'Adeyemi Realty']])->save();
    app('mailer')->getSymfonyTransport()->flush();
    ticketsDo($this->acme, fn (TicketService $s) => $s->startWork($this->admin, $ticket));
    $mail = collect(app('mailer')->getSymfonyTransport()->messages())->first()->getOriginalMessage();

    expect($mail->getFrom()[0]->getName())->toStartWith('Adeyemi Realty via ')
        ->and($mail->getReplyTo())->toBe([]);
});

describe('acceptance: once, even when a job runs again', function () {
    it('queues the telling on `notifications`, after the move commits', function () {
        Queue::fake();
        $ticket = raiseTicket($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme, ['assigneeId' => $this->agent->id]);

        Queue::assertPushedOn('notifications', CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === TellTicketParties::class && $job->data[0] instanceof TicketStatusChanged && $job->data[0]->ticketId === $ticket->id);
        Queue::assertPushedOn('notifications', CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === TellTicketParties::class && $job->data[0] instanceof TicketAssignmentChanged);
        expect(inOrganization($this->acme, fn () => TicketNotice::query()->count()))->toBe(0);
    });

    it('tells nobody twice when the listener runs again with the same event (a retried or replayed job)', function () {
        $ticket = noticeKemiTicket($this->agent);
        $event = new TicketStatusChanged($this->acme->id, $ticket->id, $ticket->number, 'submitted', 'in_progress', TicketStatusChanged::ACTOR_STAFF, $this->owner->id, $this->palm->id, $this->flat3b->id, $this->kemiAtAcme->id, $this->agent->id, null);

        $first = ticketToldBy(fn () => app(TellTicketParties::class)->handle($event));
        $again = ticketToldBy(function () use ($event) {
            app(TellTicketParties::class)->handle($event);
            app(TellTicketParties::class)->handle(unserialize(serialize($event)));
        });

        expect($first)->toEqual([TN_BOLA => ['app:ticket.in_progress', 'mail'], TN_KEMI => ['app:ticket.in_progress', 'mail']])
            ->and($again)->toBe([])
            ->and(inOrganization($this->acme, fn () => TicketNotice::query()->where('event', 'ticket.in_progress')->count()))->toBe(2);

        // The same move announced again under a new key is a new announcement (each move has its own key).
        $assignment = new TicketAssignmentChanged($this->acme->id, $ticket->id, $ticket->number, null, $this->agent->id, $this->owner->id, 'fixed-key');
        expect(ticketToldBy(fn () => app(TellTicketParties::class)->handle($assignment)))->toEqual([TN_BOLA => ['app:ticket.assigned', 'mail']])
            ->and(ticketToldBy(fn () => app(TellTicketParties::class)->handle($assignment)))->toBe([]);
    });

    it('sends no second in-app notice or email when a notification\'s own job runs again', function () {
        $ticket = noticeKemiTicket();
        Queue::fake();
        ticketsDo($this->acme, fn (TicketService $s) => $s->startWork($this->owner, $ticket));
        $listener = Queue::pushed(CallQueuedListener::class)->first(fn (CallQueuedListener $job): bool => $job->class === TellTicketParties::class);
        app(TellTicketParties::class)->handle($listener->data[0]);
        $jobs = Queue::pushed(SendQueuedNotifications::class)->filter(fn (SendQueuedNotifications $job): bool => $job->notification instanceof TicketUpdate);

        expect($jobs)->toHaveCount(4); // Kemi and Seyi, in the app and by email: one job per channel.

        $told = ticketToldBy(function () use ($jobs) {
            foreach ($jobs as $job) {
                $job->handle(app(ChannelManager::class));
            }

            // Each job retried (after a timeout, say): nothing more is sent.
            foreach ($jobs as $job) {
                unserialize(serialize($job))->handle(app(ChannelManager::class));
            }
        });

        expect($told)->toEqual([TN_KEMI => ['app:ticket.in_progress', 'mail'], TN_SEYI => ['app:ticket.in_progress', 'mail']]);
    });
});

describe('acceptance: who the staff side is', function () {
    it('tells the owners and administrators when the assignee can no longer see the ticket\'s building', function () {
        $ticket = noticeKemiTicket($this->agent);
        inOrganization($this->acme, fn () => AgentAssignment::query()->where('user_id', $this->agent->id)->get()->each->delete());
        assignIn($this->acme, $this->agent, $this->oak);

        expect(ticketToldBy(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->startWork($this->owner, $ticket))))
            ->toEqual([TN_KEMI => ['app:ticket.in_progress', 'mail'], TN_SEYI => ['app:ticket.in_progress', 'mail']]);
    });

    it('tells nobody on the staff side when the assignee moves their own ticket, and the tenant nothing about their own move', function () {
        $ticket = noticeKemiResolved($this->agent);

        expect(ticketToldBy(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->reopenAsTenant($this->kemi, $ticket, 'Still dripping'))))->toEqual([TN_BOLA => ['app:ticket.reopened', 'mail']])
            ->and(ticketToldBy(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->resolve($this->agent, $ticket))))->toEqual([TN_KEMI => ['app:ticket.resolved', 'mail']]);
    });

    it('never tells an agent of an unassigned ticket, nor property owners', function () {
        $landlord = joinAs(User::factory()->create(['name' => 'Lola Landlord', 'email' => 'lola.landlord@example.com']), $this->acme, OrganizationRole::PropertyOwner);

        expect(ticketToldBy(fn () => tenantTicket($this->acme, $this->tunde, $this->tundeLease)))->toEqual([TN_ADA => ['app:ticket.raised', 'mail'], TN_SEYI => ['app:ticket.raised', 'mail']])
            ->and(DB::table('notifications')->whereIn('notifiable_id', [$this->agent->id, $this->freeAgent->id, $landlord->id])->count())->toBe(0);
    });
});

describe('acceptance: impersonation and suspension', function () {
    it('sends nothing from a read-only support session (it changes nothing), and tells the parties of a write session\'s moves, never the impersonated actor', function () {
        $ticket = noticeKemiTicket();
        $staff = staffMember(['name' => 'Kemi Support', 'email' => 'kemi.support@goproperty.ng']);
        enterSupport(startSupport($staff, $this->owner, $this->acme))->assertRedirect('/o/acme-homes');
        $show = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/tickets/{$ticket->id}")->assertOk());

        $readOnly = ticketToldBy(function () use ($show) {
            freshRequest();
            LivewireHttp::update(test(), $show, [], [['startReview']])->assertForbidden();
            freshRequest();
            LivewireHttp::update(test(), $show, ['assigneeId' => (string) $this->agent->id], [['assign']])->assertForbidden();
        });

        expect($readOnly)->toBe([]);

        ticketsFreshRequest();
        seedStaffRoles();
        $writer = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
        enterSupport(startSupport($writer, $this->owner, $this->acme, ImpersonationMode::Write))->assertRedirect('/o/acme-homes');
        $show = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/tickets/{$ticket->id}")->assertOk());

        $write = ticketToldBy(function () use ($show) {
            freshRequest();
            LivewireHttp::update(test(), $show, [], [['startReview']])->assertOk();
        });

        expect($write)->toEqual([TN_KEMI => ['app:ticket.under_review', 'mail'], TN_SEYI => ['app:ticket.under_review', 'mail']]);
    });

    it('sends nothing for the moves a suspended organization refuses, and still tells the parties when the daily auto-close closes a ticket', function () {
        $ticket = noticeKemiResolved($this->agent);
        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $refused = ticketToldBy(function () use ($ticket) {
            expect(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->reopen($this->owner, $ticket, 'No')))->toThrow(AuthorizationException::class)
                ->and(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->confirmResolution($this->kemi, $ticket)))->toThrow(AuthorizationException::class);
        });
        $this->travel(8)->days();
        $closed = ticketToldBy(fn () => ticketsDo($this->acme, fn (TicketService $s) => $s->autoClose($ticket->id)));

        expect($refused)->toBe([])
            ->and($closed)->toEqual([TN_BOLA => ['app:ticket.closed', 'mail'], TN_KEMI => ['app:ticket.closed', 'mail']]);
    });
});
