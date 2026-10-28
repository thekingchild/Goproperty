<?php

declare(strict_types=1);

use App\Enums\InvitationKind;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\WhatsApp\LogWhatsAppSender;
use App\WhatsApp\WhatsAppMessage;
use App\WhatsApp\WhatsAppSender;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Notifications\OwnerPortalInvitation;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the owner invitation tests (P03-15). Agency actions go
 * through the owner's page and its "Owner portal" Livewire component's
 * update requests; the links are opened and used over HTTP, as the owner's
 * browser does. Email is faked (Notification::fake()); WhatsApp is the log
 * driver, whose sent() keeps each message; the queue is sync.
 */

require_once __DIR__.'/owner-world.php';

/** A fresh request, as production starts every one. */
function ownerAccessFreshRequest(): void
{
    billsFreshRequest();
}

/** The "Owner portal" component's snapshot on the owner's page, as $user. */
function ownerPanel(User $user, OwnerProfile $owner, string $slug = 'acme-homes'): string
{
    ownerAccessFreshRequest();
    $html = (string) ownersPage($user, "/{$owner->id}", $slug)->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $raw) {
        $snapshot = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === 'properties::owner-portal-access') {
            ownerAccessFreshRequest();
            test()->actingAs($user);

            return $snapshot;
        }
    }

    throw new LogicException('No owner portal panel on the page.');
}

/** One action of the panel, as the browser sends it. */
function ownerPanelCall(User $user, OwnerProfile $owner, string $method, array $params = [], string $slug = 'acme-homes'): TestResponse
{
    return LivewireHttp::update(test(), ownerPanel($user, $owner, $slug), [], [[$method, $params]]);
}

/** A value of the panel's state after an update. */
function ownerPanelState(TestResponse $response, string $key): mixed
{
    return json_decode(LivewireHttp::next($response), true)['data'][$key] ?? null;
}

/** Invites by "Show link and QR code"; returns the token shown. */
function inviteOwnerByLink(User $by, OwnerProfile $owner, string $slug = 'acme-homes'): string
{
    $url = ownerPanelState(ownerPanelCall($by, $owner, 'invite', ['link'], $slug)->assertOk(), 'shareUrl');
    expect($url)->toBeString();

    return Str::afterLast((string) $url, '/');
}

/** Invites by email; returns the token from the email. */
function inviteOwnerByEmail(User $by, OwnerProfile $owner, string $slug = 'acme-homes'): string
{
    ownerPanelCall($by, $owner, 'invite', ['email'], $slug)->assertOk()->assertSee('The invitation was emailed');

    return lastOwnerEmailToken((string) $owner->email);
}

/** Invites by the agency's WhatsApp; returns the token from the message. */
function inviteOwnerByWhatsApp(User $by, OwnerProfile $owner, string $slug = 'acme-homes'): string
{
    ownerPanelCall($by, $owner, 'invite', ['whatsapp'], $slug)->assertOk()->assertSee('sent to');

    return Str::afterLast(lastOwnerWhatsApp()->variables['invitation_link'], '/');
}

function lastOwnerEmailToken(string $email): string
{
    $sent = Notification::sent(new AnonymousNotifiable, OwnerPortalInvitation::class, fn (OwnerPortalInvitation $notification, array $channels, AnonymousNotifiable $notifiable): bool => ($notifiable->routes['mail'] ?? null) === $email);
    expect($sent)->not->toBeEmpty();
    $last = $sent->last();
    assert($last instanceof OwnerPortalInvitation);

    return Str::afterLast($last->url(), '/');
}

/** @return list<WhatsAppMessage> every WhatsApp message "sent" by the log driver in this test */
function ownerWhatsAppSent(): array
{
    $sender = app(WhatsAppSender::class);
    assert($sender instanceof LogWhatsAppSender);

    return array_map(fn (array $sent): WhatsAppMessage => $sent['message'], $sender->sent());
}

function lastOwnerWhatsApp(): WhatsAppMessage
{
    $sent = ownerWhatsAppSent();
    expect($sent)->not->toBeEmpty();

    return $sent[array_key_last($sent)];
}

/** A request from the owner's browser: a guest unless $as is given. */
function ownerBrowser(?User $as = null): mixed
{
    ownerAccessFreshRequest();

    if ($as === null) {
        test()->flushSession();

        return test();
    }

    return test()->actingAs($as);
}

function openOwnerInvitation(string $token, ?User $as = null): TestResponse
{
    return ownerBrowser($as)->get("/owner/invitations/{$token}");
}

/** Accepts as a new person (or as $as, signed in). */
function acceptOwnerInvitation(string $token, ?User $as = null, array $fields = ['name' => 'Bisi Adeyinka', 'password' => 'a-long-password-1']): TestResponse
{
    return ownerBrowser($as)->post("/owner/invitations/{$token}", $as === null ? $fields : []);
}

/** @return list<Invitation> the organization's owner invitations, oldest first */
function ownerInvitationsOf(Organization $organization): array
{
    return inOrganization($organization, fn (): array => Invitation::query()->where('kind', InvitationKind::Owner)->orderBy('id')->get()->all());
}

function freshOwner(Organization $organization, OwnerProfile $owner): OwnerProfile
{
    return inOrganization($organization, fn (): OwnerProfile => OwnerProfile::query()->withTrashed()->findOrFail($owner->id));
}
