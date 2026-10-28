<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\ImpersonationSession;
use App\Platform\Impersonation\ImpersonationMode;
use App\Tenancy\CurrentImpersonation;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Tickets\Models\TicketMessage;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/message-world.php';

/*
 * P04-02: the thread under the central guards (ARCHITECTURE.md § 4;
 * PLATFORM_CONSOLE.md § 6; P04-01 decision 6 kept: no ticket permission is
 * blocked while impersonating). A suspended organization's threads are
 * read-only for members, tenants and owners alike (files still open: a
 * read). Impersonating staff read the thread, internal notes included,
 * and in a read-only session write nothing; in write mode they reply and
 * add notes, audited with both identities. Files are never added or
 * opened in a support session (evidence and people's photos, like every
 * other uploaded file).
 */

beforeEach(function () {
    messageWorld();
    $this->ticket = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi sink leaking']);
    $this->shared = staffReply($this->acme, $this->agent, $this->ticket, 'A plumber comes tomorrow at 10.', [ticketPhoto('plumber-visit.jpg')]);
    $this->note = staffReply($this->acme, $this->agent, $this->ticket, INTERNAL_NOTE, internal: true);
    $this->file = messageFiles($this->acme, $this->shared)[0];
});

afterEach(fn () => ticketsFreshRequest());

it('acceptance: keeps the thread read-only while the organization is suspended, for members, tenants and owners, and files still open', function () {
    $staff = componentSnapshot(ticketsPage($this->owner, "/{$this->ticket->id}")->assertOk(), 'ticket-conversation');
    $tenant = componentSnapshot(repairsPage($this->kemi, "/{$this->ticket->id}")->assertOk(), 'portal-ticket-conversation');
    $owner = componentSnapshot(ownerTicketsPage($this->ngozi, "/{$this->ticket->id}")->assertOk(), 'owner-ticket-conversation');
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    // The pages still show the thread, without a form.
    ticketsPage($this->owner, "/{$this->ticket->id}")->assertOk()->assertSee(INTERNAL_NOTE)->assertDontSee('data-test="message-form"', false);
    repairsPage($this->kemi, "/{$this->ticket->id}")->assertOk()->assertSee('A plumber comes tomorrow at 10.')->assertDontSee('data-test="message-form"', false);
    ownerTicketsPage($this->ngozi, "/{$this->ticket->id}")->assertOk()->assertSee('A plumber comes tomorrow at 10.')->assertSee('data-test="owner-reply-on-hold"', false)->assertDontSee('data-test="message-form"', false);

    // A form opened before: every send refused, nothing written.
    threadUpdate($this->owner, $staff, ['body' => 'Reply while suspended'], [['send']])->assertForbidden();
    threadUpdate($this->owner, $staff, ['body' => 'Note while suspended', 'internal' => true], [['send']])->assertForbidden();
    threadUpdate($this->kemi, $tenant, ['body' => 'Tenant while suspended'], [['send']])->assertForbidden();
    threadUpdate($this->ngozi, $owner, ['body' => 'Owner while suspended'], [['send']])->assertForbidden();

    expect(fn () => staffReply($this->acme, $this->owner, $this->ticket, 'Writer'))->toThrow(AuthorizationException::class)
        ->and(fn () => staffReply($this->acme, $this->owner, $this->ticket, 'Writer note', internal: true))->toThrow(AuthorizationException::class)
        ->and(fn () => tenantReply($this->acme, $this->kemi, $this->ticket, 'Writer'))->toThrow(AuthorizationException::class)
        ->and(fn () => ownerReply($this->acme, $this->ngozi, $this->ticket, 'Writer'))->toThrow(AuthorizationException::class)
        ->and(count(threadOf($this->acme, $this->ticket)))->toBe(2);

    // Opening a file is a read.
    ticketsFreshRequest();
    $this->actingAs($this->kemi)->get("/portal/tickets/{$this->ticket->id}/files/{$this->file->id}")->assertRedirect();
    ticketsFreshRequest();
    $this->actingAs($this->owner)->get("/o/acme-homes/tickets/{$this->ticket->id}/files/{$this->file->id}")->assertRedirect();
});

it('acceptance: lets impersonating staff read the thread and its notes in a read-only session, and write nothing', function () {
    $staff = staffMember(['name' => 'Kemi Support', 'email' => 'kemi.support@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme))->assertRedirect('/o/acme-homes');

    $page = asSupport()->get("/o/acme-homes/tickets/{$this->ticket->id}")->assertOk()->assertSee(INTERNAL_NOTE)->assertSee('plumber-visit')
        ->assertDontSee('data-test="message-form"', false)
        // No file link in a support session: the name only.
        ->assertDontSee("/files/{$this->file->id}", false);
    $snapshot = componentSnapshot($page, 'ticket-conversation');

    freshRequest();
    LivewireHttp::update(test(), $snapshot, ['body' => 'Support reply'], [['send']])->assertForbidden();
    freshRequest();
    LivewireHttp::update(test(), $snapshot, ['body' => 'Support note', 'internal' => true], [['send']])->assertForbidden();
    asSupport()->get("/o/acme-homes/tickets/{$this->ticket->id}/files/{$this->file->id}")->assertForbidden();

    expect(count(threadOf($this->acme, $this->ticket)))->toBe(2);
});

it('acceptance: lets impersonating staff in write mode reply and add notes, audited with both identities, but never add or open files', function () {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write))->assertRedirect('/o/acme-homes');

    $page = asSupport()->get("/o/acme-homes/tickets/{$this->ticket->id}")->assertOk()->assertSee('data-test="message-form"', false)
        ->assertDontSee('data-test="message-files-input"', false);
    $snapshot = componentSnapshot($page, 'ticket-conversation');

    freshRequest();
    LivewireHttp::update(test(), $snapshot, ['body' => 'Support: the plumber is confirmed.'], [['send']])->assertOk();
    freshRequest();
    LivewireHttp::update(test(), $snapshot, ['body' => 'Support: customer asked us to chase the landlord.', 'internal' => true], [['send']])->assertOk();

    // Files: the writer refuses them in a session (the session as the next request resumes it), and the file route too.
    app(CurrentImpersonation::class)->begin(ImpersonationSession::query()->latest('id')->firstOrFail());
    expect(fn () => staffReply($this->acme, $this->owner, $this->ticket, 'With a photo', [ticketPhoto()]))->toThrow(AuthorizationException::class);
    app(CurrentImpersonation::class)->clear();
    asSupport()->get("/o/acme-homes/tickets/{$this->ticket->id}/files/{$this->file->id}")->assertForbidden();

    $posted = AuditEntry::query()->where('event', 'ticket.message_posted')->latest('id')->first();
    $noted = AuditEntry::query()->where('event', 'ticket.note_added')->latest('id')->first();
    expect(array_slice(threadOf($this->acme, $this->ticket), 2))->toBe([
        ['staff', 'Support: the plumber is confirmed.', false],
        ['staff', 'Support: customer asked us to chase the landlord.', true],
    ])
        ->and($posted->causer_id)->toBe($this->owner->id)
        ->and($posted->impersonator_staff_id)->toBe($staff->id)
        ->and($noted->causer_id)->toBe($this->owner->id)
        ->and($noted->impersonator_staff_id)->toBe($staff->id)
        ->and(inOrganization($this->acme, fn () => TicketMessage::query()->where('attachment_count', '>', 0)->count()))->toBe(1);
});

it('lets staff impersonating a tenant read their thread (never notes), reply in write mode, and never open files', function (ImpersonationMode $mode) {
    // Kemi also works for Acme as an agent: staff impersonate her in Acme.
    joinAs($this->kemi, $this->acme, OrganizationRole::Agent);
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->kemi, $this->acme, $mode))->assertRedirect();

    $page = asSupport()->get("/portal/tickets/{$this->ticket->id}")->assertOk()->assertSee('A plumber comes tomorrow at 10.')->assertDontSee('STAFF-ONLY')
        ->assertDontSee("/files/{$this->file->id}", false)->assertDontSee('data-test="message-files-input"', false);
    asSupport()->get("/portal/tickets/{$this->ticket->id}/files/{$this->file->id}")->assertForbidden();

    freshRequest();
    $sent = LivewireHttp::update(test(), componentSnapshot($page, 'portal-ticket-conversation'), ['body' => 'Sent by support for Kemi'], [['send']]);

    if ($mode === ImpersonationMode::ReadOnly) {
        $sent->assertForbidden();
        expect(count(threadOf($this->acme, $this->ticket)))->toBe(2);

        return;
    }

    $sent->assertOk();
    $audit = AuditEntry::query()->where('event', 'ticket.message_posted')->latest('id')->first();
    expect(threadOf($this->acme, $this->ticket)[2])->toBe(['tenant', 'Sent by support for Kemi', false])
        ->and($audit->causer_id)->toBe($this->kemi->id)
        ->and($audit->impersonator_staff_id)->toBe($staff->id);
})->with([
    'read-only' => [ImpersonationMode::ReadOnly],
    'write' => [ImpersonationMode::Write],
]);

it('answers 404 for another organization\'s ticket and file, and a narrowed agent\'s other building, on every thread route', function () {
    $foreign = raiseTicket($this->other, $this->otherOwner, $this->foreignUnit, $this->foreignTenant, ['title' => 'Foreign roof leaking']);
    $foreignMessage = staffReply($this->other, $this->otherOwner, $foreign, 'Foreign reply', [ticketPhoto('foreign.jpg')]);
    $foreignFile = messageFiles($this->other, $foreignMessage)[0];
    $oak = raiseTicket($this->acme, $this->owner, $this->oakUnit, $this->femi, ['title' => 'Oak pump']);

    ticketsFreshRequest();
    $this->actingAs($this->owner)->get("/o/acme-homes/tickets/{$foreign->id}/files/{$foreignFile->id}")->assertNotFound();
    ticketsFreshRequest();
    $this->actingAs($this->owner)->get("/o/acme-homes/tickets/{$this->ticket->id}/files/{$foreignFile->id}")->assertNotFound();
    ticketsFreshRequest();
    $this->actingAs($this->otherOwner)->get("/o/acme-homes/tickets/{$this->ticket->id}/files/{$this->file->id}")->assertNotFound();
    ticketsFreshRequest();
    $this->actingAs($this->agent)->get("/o/acme-homes/tickets/{$oak->id}/files/{$this->file->id}")->assertNotFound();
    ticketsFreshRequest();
    $this->actingAs($this->kemi)->get("/portal/tickets/{$foreign->id}/files/{$foreignFile->id}")->assertNotFound();
    ticketsFreshRequest();
    $this->actingAs($this->ngozi)->get("/owner/tickets/{$foreign->id}/files/{$foreignFile->id}")->assertNotFound();
});
