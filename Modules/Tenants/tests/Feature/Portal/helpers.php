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
use Modules\Tenants\Models\TenantProfile;
use Modules\Tenants\Notifications\TenantPortalInvitation;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the tenant portal access tests (P03-01b), required by the
 * files in this folder. Agency actions go through the tenant record page
 * and the "Portal access" Livewire component's update requests; the links
 * are opened and used over HTTP, as the tenant's browser does. Email is
 * faked (Notification::fake()); WhatsApp is the log driver, whose sent()
 * keeps each message; the queue is sync.
 */

require_once __DIR__.'/../Pages/helpers.php';

/** The "Portal access" component's snapshot on the tenant's record page, as $user. */
function portalPanel(User $user, TenantProfile $tenant, string $slug = 'acme-homes'): string
{
    $html = (string) tenantsPage($user, "/{$tenant->id}", $slug)->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $raw) {
        $snapshot = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === 'tenants::tenant-portal-access') {
            tenantPagesFreshRequest();
            test()->actingAs($user);

            return $snapshot;
        }
    }

    throw new LogicException('No portal access panel on the page.');
}

/** One action of the panel, as the browser sends it. */
function portalCall(User $user, TenantProfile $tenant, string $method, array $params = [], string $slug = 'acme-homes'): TestResponse
{
    return LivewireHttp::update(test(), portalPanel($user, $tenant, $slug), [], [[$method, $params]]);
}

/** A value of the panel's state after an update. */
function portalState(TestResponse $response, string $key): mixed
{
    return json_decode(LivewireHttp::next($response), true)['data'][$key] ?? null;
}

function linkToken(string $url): string
{
    return Str::afterLast($url, '/');
}

/** Invites by "Show link and QR code"; returns the token shown. */
function inviteByLink(User $by, TenantProfile $tenant, string $slug = 'acme-homes'): string
{
    $url = portalState(portalCall($by, $tenant, 'invite', ['link'], $slug)->assertOk(), 'shareUrl');
    expect($url)->toBeString();

    return linkToken((string) $url);
}

/** Invites by email; returns the token from the email. */
function inviteByEmail(User $by, TenantProfile $tenant, string $slug = 'acme-homes'): string
{
    portalCall($by, $tenant, 'invite', ['email'], $slug)->assertOk()->assertSee('The invitation was emailed');

    return lastPortalEmailToken((string) $tenant->email);
}

/** Invites by the agency's WhatsApp; returns the token from the message. */
function inviteByWhatsApp(User $by, TenantProfile $tenant, string $slug = 'acme-homes'): string
{
    portalCall($by, $tenant, 'invite', ['whatsapp'], $slug)->assertOk()->assertSee('sent to');

    return linkToken(lastWhatsApp()->variables['invitation_link']);
}

function lastPortalEmailToken(string $email): string
{
    $sent = Notification::sent(new AnonymousNotifiable, TenantPortalInvitation::class, fn (TenantPortalInvitation $notification, array $channels, AnonymousNotifiable $notifiable): bool => ($notifiable->routes['mail'] ?? null) === $email);
    expect($sent)->not->toBeEmpty();
    $last = $sent->last();
    assert($last instanceof TenantPortalInvitation);

    return linkToken($last->url());
}

/** @return list<WhatsAppMessage> every WhatsApp message "sent" by the log driver in this test */
function whatsAppSent(): array
{
    $sender = app(WhatsAppSender::class);
    assert($sender instanceof LogWhatsAppSender);

    return array_map(fn (array $sent): WhatsAppMessage => $sent['message'], $sender->sent());
}

function lastWhatsApp(): WhatsAppMessage
{
    $sent = whatsAppSent();
    expect($sent)->not->toBeEmpty();

    return $sent[array_key_last($sent)];
}

/** A request from the tenant's browser: a guest unless $as is given. */
function asBrowser(?User $as = null): mixed
{
    tenantPagesFreshRequest();

    if ($as === null) {
        test()->flushSession();

        return test();
    }

    return test()->actingAs($as);
}

/** Opens a tenant invitation link. */
function openPortalInvitation(string $token, ?User $as = null): TestResponse
{
    return asBrowser($as)->get("/portal/invitations/{$token}");
}

/** Accepts as a new person (or as $as, signed in). */
function acceptPortalInvitation(string $token, ?User $as = null, array $fields = ['name' => 'Adaeze Okafor', 'password' => 'a-long-password-1']): TestResponse
{
    return asBrowser($as)->post("/portal/invitations/{$token}", $as === null ? $fields : []);
}

/** A phone-only account linked to the tenant (as an accepted WhatsApp or link invitation leaves it). */
function portalAccountFor(Organization $organization, TenantProfile $tenant, array $attributes = []): User
{
    $user = User::factory()->create($attributes + ['name' => $tenant->fullName(), 'email' => null, 'email_verified_at' => null, 'phone' => $tenant->phone]);
    inOrganization($organization, fn () => $tenant->forceFill(['user_id' => $user->id])->save());

    return $user;
}

/** Makes a sign-in link with "Show link and QR code"; returns the token shown. */
function signInLinkByLink(User $by, TenantProfile $tenant, string $slug = 'acme-homes'): string
{
    $url = portalState(portalCall($by, $tenant, 'createSignInLink', ['link'], $slug)->assertOk(), 'shareUrl');
    expect($url)->toBeString();

    return linkToken((string) $url);
}

/** @return list<Invitation> the organization's tenant invitations and sign-in links, oldest first */
function portalInvitationsOf(Organization $organization, ?InvitationKind $kind = null): array
{
    return inOrganization($organization, fn (): array => Invitation::query()
        ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
        ->whereIn('kind', [InvitationKind::Tenant, InvitationKind::SignInLink])
        ->orderBy('id')->get()->all());
}

function freshTenant(Organization $organization, TenantProfile $tenant): TenantProfile
{
    return inOrganization($organization, fn (): TenantProfile => TenantProfile::query()->withTrashed()->findOrFail($tenant->id));
}
