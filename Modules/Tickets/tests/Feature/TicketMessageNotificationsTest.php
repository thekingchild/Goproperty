<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Tickets\Events\TicketMessagePosted;
use Modules\Tickets\Listeners\TellTicketParties;
use Modules\Tickets\Models\TicketNotice;
use Modules\Tickets\Tickets\TicketService;

require_once __DIR__.'/message-world.php';
require_once __DIR__.'/notices.php';

/*
 * P04-03 notifications for P04-02's thread (Events\TicketMessagePosted):
 * each party once per message, never the author. A tenant's or the
 * building owner's reply tells the staff side (the assignee, else the
 * owners and administrators); a member's reply tells the tenant and the
 * staff side; a member's internal note tells the staff side only — never
 * the tenant or the owner, and its words are quoted to nobody. Property
 * owners are not told in P04. Phone-only tenants hear nothing until P05.
 *
 * World: ticket-world.php plus message-world.php (Ngozi owns Palm Court
 * and is linked to it in /owner).
 */

beforeEach(function () {
    messageWorld();
    $this->ticket = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi sink leaking']);
});

afterEach(fn () => ticketsFreshRequest());

const TN_NOTE = 'Kemi owes two months; do not promise a date before the plumber quotes.';

describe('acceptance: each message notifies the right party once', function () {
    it('tells the owners and administrators about a tenant\'s reply to an unassigned ticket, and the assignee once there is one', function () {
        expect(ticketToldBy(fn () => tenantReply($this->acme, $this->kemi, $this->ticket, 'It is dripping again.')))
            ->toEqual([TN_ADA => ['app:ticket.replied', 'mail'], TN_SEYI => ['app:ticket.replied', 'mail']]);

        ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $this->ticket, $this->agent->id));

        expect(ticketToldBy(fn () => tenantReply($this->acme, $this->kemi, $this->ticket, 'Still dripping.')))
            ->toEqual([TN_BOLA => ['app:ticket.replied', 'mail']])
            ->and(DB::table('notifications')->where('notifiable_id', $this->ngozi->id)->count())->toBe(0);
    });

    it('tells the staff side about the building owner\'s reply, never the owner themselves, nor the tenant', function () {
        expect(ticketToldBy(fn () => ownerReply($this->acme, $this->ngozi, $this->ticket, 'I have asked the agency to send a plumber.')))
            ->toEqual([TN_ADA => ['app:ticket.replied', 'mail'], TN_SEYI => ['app:ticket.replied', 'mail']]);
    });

    it('tells the tenant and the staff side about a member\'s reply, never its author', function () {
        ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $this->ticket, $this->agent->id));

        // The administrator replies: the tenant and the assignee.
        expect(ticketToldBy(fn () => staffReply($this->acme, $this->admin, $this->ticket, 'A plumber comes tomorrow at 10.')))
            ->toEqual([TN_BOLA => ['app:ticket.replied', 'mail'], TN_KEMI => ['app:ticket.replied', 'mail']])
            // The assignee replies: only the tenant.
            ->and(ticketToldBy(fn () => staffReply($this->acme, $this->agent, $this->ticket, 'On my way.')))
            ->toEqual([TN_KEMI => ['app:ticket.replied', 'mail']]);

        // Unassigned, the owner replies: the tenant and the administrator, not the owner.
        ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $this->ticket, null));
        expect(ticketToldBy(fn () => staffReply($this->acme, $this->owner, $this->ticket, 'We will call you.')))
            ->toEqual([TN_KEMI => ['app:ticket.replied', 'mail'], TN_SEYI => ['app:ticket.replied', 'mail']]);
    });

    it('acceptance: tells only the staff side about an internal note — never the tenant or the owner — and quotes it to nobody', function () {
        ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $this->ticket, $this->agent->id));
        app('mailer')->getSymfonyTransport()->flush();

        $told = ticketToldBy(fn () => staffReply($this->acme, $this->admin, $this->ticket, TN_NOTE, internal: true));
        $mail = collect(app('mailer')->getSymfonyTransport()->messages())->sole()->getOriginalMessage();
        $inApp = DB::table('notifications')->where('notifiable_id', $this->agent->id)->sole();

        expect($told)->toEqual([TN_BOLA => ['app:ticket.note_added', 'mail']])
            ->and(inOrganization($this->acme, fn () => TicketNotice::query()->where('event', 'ticket.note_added')->pluck('side')->all()))->toBe(['staff'])
            ->and(DB::table('notifications')->whereIn('notifiable_id', [$this->kemi->id, $this->ngozi->id])->count())->toBe(0)
            ->and($mail->getSubject())->toBe('Internal note on TKT-000001: Kemi sink leaking')
            ->and($mail->getHtmlBody())->toContain('internal note (staff only)')->not->toContain('owes two months')
            ->and((string) $inApp->data)->not->toContain('owes two months');

        // Unassigned: the owners and administrators but the author; still not the tenant.
        ticketsDo($this->acme, fn (TicketService $s) => $s->assign($this->owner, $this->ticket, null));
        expect(ticketToldBy(fn () => staffReply($this->acme, $this->agent, $this->ticket, TN_NOTE, internal: true)))
            ->toEqual([TN_ADA => ['app:ticket.note_added', 'mail'], TN_SEYI => ['app:ticket.note_added', 'mail']]);
    });

    it('quotes a short excerpt of a reply, and links the tenant to the portal and staff to the workspace', function () {
        app('mailer')->getSymfonyTransport()->flush();
        staffReply($this->acme, $this->admin, $this->ticket, "A plumber comes tomorrow at 10.\n\nPlease keep the kitchen clear. ".str_repeat('More details follow. ', 20));
        $mails = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($message) => $message->getOriginalMessage())
            ->keyBy(fn ($message): string => $message->getTo()[0]->getAddress());

        expect($mails->keys()->sort()->values()->all())->toBe([TN_ADA, TN_KEMI])
            ->and($mails[TN_KEMI]->getSubject())->toBe('Adeyemi Realty Ltd replied about your repair TKT-000001')
            ->and($mails[TN_KEMI]->getHtmlBody())->toContain('A plumber comes tomorrow at 10. Please keep the kitchen clear.')->toContain('…')
            ->not->toContain(str_repeat('More details follow. ', 20))
            ->toContain(route('portal.tickets.show', $this->ticket->id))
            ->and($mails[TN_ADA]->getSubject())->toBe('Seyi Admin replied on TKT-000001: Kemi sink leaking')
            ->and($mails[TN_ADA]->getHtmlBody())->toContain(route('workspace.tickets.show', ['organization' => 'acme-homes', 'ticket' => $this->ticket->id]));
    });
});

describe('acceptance: once, and only for those who can hear', function () {
    it('tells nobody twice when the listener runs again for the same message (a retried or replayed job)', function () {
        $message = tenantReply($this->acme, $this->kemi, $this->ticket, 'It is dripping again.');
        $event = new TicketMessagePosted($this->acme->id, $this->ticket->id, (string) $this->ticket->number, $message->id, 'tenant', $this->kemi->id, false, $this->kemiAtAcme->id, null, $this->palm->id, 0);

        expect(ticketToldBy(function () use ($event) {
            app(TellTicketParties::class)->handle($event);
            app(TellTicketParties::class)->handle(unserialize(serialize($event)));
        }))->toBe([])
            ->and(inOrganization($this->acme, fn () => TicketNotice::query()->where('key', 'message:'.$message->id)->count()))->toBe(2);
    });

    it('tells a phone-only tenant nothing about a member\'s reply, and a tenant without a portal account by email only', function () {
        $phoneOnly = User::factory()->create(['name' => 'Musa Bello', 'email' => null, 'phone' => '+2348037778888']);
        $musa = tenantIn($this->acme, ['first_name' => 'Musa', 'last_name' => 'Bello', 'phone' => '+2348037778888', 'email' => null, 'user_id' => $phoneOnly->id]);
        $musaTicket = tenantTicket($this->acme, $phoneOnly, activeLease($this->acme, $this->owner, $this->gatehouse, $musa, ['rent' => '1,500,000']));
        $femiTicket = raiseTicket($this->acme, $this->owner, $this->oakUnit, $this->femi);

        expect(ticketToldBy(fn () => staffReply($this->acme, $this->owner, $musaTicket, 'We will come on Monday.')))->toEqual([TN_SEYI => ['app:ticket.replied', 'mail']])
            ->and(ticketToldBy(fn () => staffReply($this->acme, $this->owner, $femiTicket, 'We will come on Monday.')))->toEqual([TN_FEMI => ['mail'], TN_SEYI => ['app:ticket.replied', 'mail']]);
    });

    it('sends nothing for a reply a suspended organization refuses', function () {
        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

        expect(ticketToldBy(function () {
            expect(fn () => staffReply($this->acme, $this->owner, $this->ticket, 'Hello'))->toThrow(AuthorizationException::class)
                ->and(fn () => tenantReply($this->acme, $this->kemi, $this->ticket, 'Hello'))->toThrow(AuthorizationException::class);
        }))->toBe([]);
    });
});
