<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Audit\AuthenticationLog;
use App\Enums\InvitationChannel;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Enums\PhoneVerificationMethod;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\WhatsApp\LogWhatsAppSender;
use App\WhatsApp\WhatsAppMessage;
use App\WhatsApp\WhatsAppSender;
use App\WhatsApp\WhatsAppUnavailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Tenants\Notifications\TenantPortalInvitation;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P03-01 acceptance: "Each invite channel produces a working one-time
 * link; used/expired links rejected" (ONBOARDING_COMPLIANCE.md § 2
 * "Tenants"). The agency invites from the tenant's record by email, by its
 * WhatsApp (the log stand-in until P05-06) or with a link / QR code; the
 * tenant opens the link, sets a password and lands in the portal, never in
 * a workspace.
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner']), $this->acme);
    $this->tenant = tenantIn($this->acme, ['first_name' => 'Adaeze', 'last_name' => 'Okafor', 'email' => 'adaeze@example.ng', 'phone' => '+2348031234567']);
});

afterEach(fn () => tenantPagesFreshRequest());

it('acceptance: an emailed invitation link sets up a working account, once, and lands in the portal', function () {
    $token = inviteByEmail($this->owner, $this->tenant);

    // Email only, queued on `notifications`.
    Notification::assertSentOnDemand(TenantPortalInvitation::class, function (TenantPortalInvitation $notification, array $channels, AnonymousNotifiable $notifiable): bool {
        expect($channels)->toBe(['mail'])->and($notification->queue)->toBe('notifications')
            ->and($notification->toMail($notifiable)->subject)->toContain('Acme Homes');

        return $notifiable->routes['mail'] === 'adaeze@example.ng';
    });

    openPortalInvitation($token)->assertOk()->assertSee('Welcome, Adaeze')->assertSee('Ngozi Owner')->assertSee('Acme Homes')
        ->assertSee('adaeze@example.ng')->assertSee('Choose a password');
    acceptPortalInvitation($token)->assertRedirect('/portal/welcome');

    $user = User::query()->where('email', 'adaeze@example.ng')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->phone)->toBe('+2348031234567')
        ->and($user->phone_verified_at)->toBeNull()
        ->and(freshTenant($this->acme, $this->tenant)->user_id)->toBe($user->id);

    // The portal and the optional passkey open; no workspace, no membership anywhere.
    tenantPagesFreshRequest();
    $this->get('/portal/welcome')->assertOk()->assertSee('Add a passkey')->assertSee('email address and password');
    $this->get('/settings/security')->assertOk();
    $this->get('/portal')->assertOk();
    $this->get('/o/acme-homes')->assertNotFound();
    expect(Membership::query()->withoutGlobalScopes()->where('user_id', $user->id)->exists())->toBeFalse();

    // Recorded: accepted, in the authentication log and the audit log.
    [$invitation] = portalInvitationsOf($this->acme);
    expect($invitation->kind)->toBe(InvitationKind::Tenant)
        ->and($invitation->channel)->toBe(InvitationChannel::Email)
        ->and($invitation->status)->toBe(InvitationStatus::Accepted)
        ->and($invitation->accepted_by_user_id)->toBe($user->id)
        ->and($invitation->roles)->toBe([])
        ->and(AuthenticationLog::query()->where('event', 'invitation_accepted')->where('authenticatable_id', $user->id)->count())->toBe(1);
    $entry = AuditEntry::query()->where('event', 'tenant.portal_access_granted')->sole();
    expect($entry->causer_id)->toBe($user->id)->and($entry->subject_id)->toBe($this->tenant->id)->and($entry->getProperty('channel'))->toBe('email');
    $this->artisan('goproperty:audit-verify')->assertSuccessful();

    // The link worked once.
    openPortalInvitation($token)->assertNotFound()->assertSee('This link no longer works');
    acceptPortalInvitation($token)->assertNotFound();
    expect(User::query()->where('email', 'adaeze@example.ng')->count())->toBe(1);

    // Signing in later lands on the portal, by email or phone number.
    asBrowser();
    $this->post('/login', ['identifier' => 'adaeze@example.ng', 'password' => 'a-long-password-1'])->assertRedirect('/portal');
    asBrowser();
    $this->post('/login', ['identifier' => '0803 123 4567', 'password' => 'a-long-password-1'])->assertRedirect('/portal');
});

it('acceptance: a WhatsApp invitation link works once and verifies the phone number (whatsapp_link)', function () {
    $token = inviteByWhatsApp($this->owner, $this->tenant);

    $message = lastWhatsApp();
    expect(whatsAppSent())->toHaveCount(1)
        ->and($message->to)->toBe('+2348031234567')
        ->and($message->event)->toBe('tenant_invitation')
        ->and($message->text)->toContain('Acme Homes')->toContain("/portal/invitations/{$token}");
    Notification::assertNothingSent();

    openPortalInvitation($token)->assertOk()->assertSee('+234 ••• 4567')->assertDontSee('adaeze@example.ng');
    acceptPortalInvitation($token)->assertRedirect('/portal/welcome');

    // A phone-only account: the number the message reached, verified by opening it.
    $user = User::query()->where('phone', '+2348031234567')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->email)->toBeNull()
        ->and($user->phone_verified_at)->not->toBeNull()
        ->and($user->phone_verification_method)->toBe(PhoneVerificationMethod::WhatsAppLink)
        ->and($user->phone_confirmed_by_user_id)->toBeNull()
        ->and(freshTenant($this->acme, $this->tenant)->user_id)->toBe($user->id)
        ->and(AuditEntry::query()->where('event', 'tenant.portal_access_granted')->sole()->getProperty('phone_verified'))->toBeTrue();

    // With no email to confirm, the portal and the welcome page open; signup of an organization does not.
    tenantPagesFreshRequest();
    $this->get('/portal')->assertOk();
    $this->get('/portal/welcome')->assertOk()->assertSee('phone number and password');
    $this->get('/dashboard')->assertRedirect('/portal');
    $this->get('/signup/organization')->assertForbidden();

    openPortalInvitation($token)->assertNotFound();
    acceptPortalInvitation($token)->assertNotFound();
    asBrowser();
    $this->post('/login', ['identifier' => '+234 803 123 4567', 'password' => 'a-long-password-1'])->assertRedirect('/portal');
});

it('acceptance: a copy link / QR code works once; the phone is not verified until the agency confirms it in person', function () {
    $response = portalCall($this->owner, $this->tenant, 'invite', ['link'])->assertOk();
    $url = (string) portalState($response, 'shareUrl');
    expect($url)->toStartWith(url('/portal/invitations/'))
        ->and(LivewireHttp::html($response))->toContain($url)->toContain('data-test="portal-qr"')->toContain('src="data:image/png;base64,')->toContain('Print');
    Notification::assertNothingSent();
    expect(whatsAppSent())->toBe([]);

    // Shown once: closing the panel forgets it; only the hash is stored.
    [$invitation] = portalInvitationsOf($this->acme);
    expect($invitation->token_hash)->toBe(hash('sha256', linkToken($url)))
        ->and(json_encode($invitation->getAttributes()))->not->toContain(linkToken($url));

    acceptPortalInvitation(linkToken($url))->assertRedirect('/portal/welcome');
    $user = User::query()->where('phone', '+2348031234567')->sole();
    expect($user->email)->toBeNull()->and($user->phone_verified_at)->toBeNull();

    // "Phone number confirmed in person", recording who.
    portalCall($this->owner, $this->tenant, 'confirmPhone')->assertOk()->assertSee('is now confirmed');
    $user->refresh();
    expect($user->phone_verified_at)->not->toBeNull()
        ->and($user->phone_verification_method)->toBe(PhoneVerificationMethod::AgencyConfirmed)
        ->and($user->phone_confirmed_by_user_id)->toBe($this->owner->id);
    $entry = AuditEntry::query()->where('event', 'tenant.phone_confirmed')->sole();
    expect($entry->causer_id)->toBe($this->owner->id)->and($entry->getProperty('user_id'))->toBe($user->id);

    // Once only.
    tenantsPage($this->owner, "/{$this->tenant->id}")->assertSee('Confirmed in person by the agency')->assertSee('Ngozi Owner')->assertDontSee('data-test="confirm-phone"', false);
    portalCall($this->owner, $this->tenant, 'confirmPhone')->assertOk()->assertSee('already verified');
    expect(AuditEntry::query()->where('event', 'tenant.phone_confirmed')->count())->toBe(1);

    openPortalInvitation(linkToken($url))->assertNotFound();
});

it('rejects expired, used and revoked links, and unknown ones, all the same way', function () {
    $expiring = inviteByLink($this->owner, $this->tenant);
    $other = tenantIn($this->acme, ['phone' => '+2348039990000']);
    $revoked = inviteByLink($this->owner, $other);

    // On the last day the link still works; a minute past the 14 days it does not.
    $this->travel(14)->days();
    $this->travel(-1)->minutes();
    openPortalInvitation($expiring)->assertOk();
    $this->travel(2)->minutes();
    $unavailable = openPortalInvitation($expiring)->assertNotFound()->assertSee('This link no longer works')->getContent();
    acceptPortalInvitation($expiring)->assertNotFound();
    expect(portalInvitationsOf($this->acme)[0]->status)->toBe(InvitationStatus::Expired);
    $this->travelBack();

    portalCall($this->owner, $other, 'revoke')->assertOk()->assertSee('was revoked');
    expect(openPortalInvitation($revoked)->assertNotFound()->getContent())->toBe($unavailable);
    acceptPortalInvitation($revoked)->assertNotFound();
    expect(portalInvitationsOf($this->acme)[1]->status)->toBe(InvitationStatus::Cancelled)
        ->and(AuditEntry::query()->where('event', 'tenant.portal_invitation_revoked')->count())->toBe(1);

    // Unknown and malformed links, and other kinds of link, answer the same.
    expect(openPortalInvitation(str_repeat('a', 48))->assertNotFound()->getContent())->toBe($unavailable);
    $this->get('/portal/invitations/'.str_repeat('a', 129))->assertNotFound();
    acceptPortalInvitation(str_repeat('b', 48))->assertNotFound();

    expect(User::query()->where('phone', '+2348031234567')->exists())->toBeFalse()
        ->and(freshTenant($this->acme, $this->tenant)->user_id)->toBeNull();
});

it('sends an invitation again with a new link, by any channel: the old link stops working', function () {
    $first = inviteByEmail($this->owner, $this->tenant);

    $response = portalCall($this->owner, $this->tenant, 'resend', ['link'])->assertOk()->assertSee('The old one no longer works');
    $second = linkToken((string) portalState($response, 'shareUrl'));
    openPortalInvitation($first)->assertNotFound();
    openPortalInvitation($second)->assertOk();

    portalCall($this->owner, $this->tenant, 'resend', ['whatsapp'])->assertOk();
    $third = linkToken(lastWhatsApp()->variables['invitation_link']);
    openPortalInvitation($second)->assertNotFound();

    [$invitation] = portalInvitationsOf($this->acme);
    expect(portalInvitationsOf($this->acme))->toHaveCount(1)
        ->and($invitation->send_count)->toBe(3)
        ->and($invitation->channel)->toBe(InvitationChannel::WhatsApp)
        ->and($invitation->expires_at?->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'tenant.portal_invitation_resent')->count())->toBe(2);

    // An expired invitation can be sent again too.
    $this->travel(15)->days();
    openPortalInvitation($third)->assertNotFound();
    tenantsPage($this->owner, "/{$this->tenant->id}")->assertSee('expired on');
    $fourth = linkToken((string) portalState(portalCall($this->owner, $this->tenant, 'resend', ['link'])->assertOk(), 'shareUrl'));
    acceptPortalInvitation($fourth)->assertRedirect('/portal/welcome');
    // The account got the number the last link was bound to; the WhatsApp link was never opened, so it is not verified.
    expect(User::query()->where('phone', '+2348031234567')->sole()->phone_verified_at)->toBeNull();
});

it('never sends an SMS: only email and the agency\'s WhatsApp, and nothing leaves the server', function () {
    Http::fake();
    $phoneOnly = tenantIn($this->acme, ['email' => null, 'phone' => '+2348032220000']);

    inviteByEmail($this->owner, $this->tenant);
    inviteByWhatsApp($this->owner, $phoneOnly);
    acceptPortalInvitation(linkToken(lastWhatsApp()->variables['invitation_link']))->assertRedirect('/portal/welcome');
    $account = User::query()->where('phone', '+2348032220000')->sole();
    signInLinkByLink($this->owner, freshTenant($this->acme, $phoneOnly));
    portalCall($this->owner, freshTenant($this->acme, $phoneOnly), 'createSignInLink', ['whatsapp'])->assertOk();

    // Every notification went by mail; every phone message by the agency's WhatsApp; no HTTP call to any provider.
    Notification::assertSentOnDemandTimes(TenantPortalInvitation::class, 1);
    $channels = collect(Notification::sentNotifications())->flatten(3)->pluck('channels')->flatten()->unique()->values()->all();
    expect($channels)->toBe(['mail']);
    expect(collect(whatsAppSent())->pluck('event')->all())->toBe(['tenant_invitation', 'tenant_sign_in_link'])
        ->and(collect(whatsAppSent())->pluck('to')->unique()->all())->toBe(['+2348032220000']);
    Http::assertNothingSent();

    // No SMS channel or driver anywhere in the code that sends these links.
    foreach ([base_path('app/WhatsApp'), dirname(__DIR__, 3).'/app/Portal', dirname(__DIR__, 3).'/app/Notifications', dirname(__DIR__, 3).'/app/Livewire'] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            expect(preg_match('/\b(sms|termii|vonage|nexmo|twilio)\b/i', preg_replace('#^\s*(\*|//).*$#m', '', (string) file_get_contents($file->getPathname())) ?? ''))
                ->toBe(0, $file->getRelativePathname());
        }
    }
    expect($account->phone_verification_method)->toBe(PhoneVerificationMethod::WhatsAppLink);
});

it('offers WhatsApp only when the organization can send it, and never pretends to in production', function () {
    tenantsPage($this->owner, "/{$this->tenant->id}")->assertSee('data-test="invite-email"', false)
        ->assertSee('data-test="invite-whatsapp"', false)->assertSee('data-test="invite-link"', false);

    config(['services.whatsapp.driver' => 'none']);
    app()->forgetInstance(WhatsAppSender::class);
    tenantsPage($this->owner, "/{$this->tenant->id}")->assertDontSee('data-test="invite-whatsapp"', false);
    portalCall($this->owner, $this->tenant, 'invite', ['whatsapp'])->assertOk()->assertSee('WhatsApp is not connected');
    expect(portalInvitationsOf($this->acme))->toBe([]);

    // The log stand-in is never "connected" in production.
    $this->app->detectEnvironment(fn (): string => 'production');
    expect((new LogWhatsAppSender)->available($this->acme))->toBeFalse();
    expect(fn () => (new LogWhatsAppSender)->send($this->acme, new WhatsAppMessage('+2348031234567', 'tenant_invitation', 'Hi')))
        ->toThrow(WhatsAppUnavailable::class);
});

it('refuses inviting a tenant who has an account, already has an invitation waiting, or is archived; email needs an address', function () {
    inviteByLink($this->owner, $this->tenant);
    portalCall($this->owner, $this->tenant, 'invite', ['email'])->assertOk()->assertSee('already has an invitation');

    $noEmail = tenantIn($this->acme, ['email' => null, 'phone' => '+2348035550000']);
    tenantsPage($this->owner, "/{$noEmail->id}")->assertDontSee('data-test="invite-email"', false)->assertSee('Add an email address to the tenant');
    portalCall($this->owner, $noEmail, 'invite', ['email'])->assertOk()->assertSee('Add the tenant\'s email address first');
    portalCall($this->owner, $noEmail, 'invite', ['sms'])->assertNotFound();

    $linked = tenantIn($this->acme, ['phone' => '+2348035550001']);
    portalAccountFor($this->acme, $linked);
    portalCall($this->owner, freshTenant($this->acme, $linked), 'invite', ['link'])->assertOk()->assertSee('already has a portal account');

    $archived = tenantIn($this->acme, ['phone' => '+2348035550002']);
    inOrganization($this->acme, fn () => $archived->delete());
    tenantsPage($this->owner, "/{$archived->id}")->assertSee('Archived tenants cannot be invited')->assertDontSee('data-test="invite-link"', false);
    portalCall($this->owner, freshTenant($this->acme, $archived), 'invite', ['link'])->assertForbidden();

    // An archived tenant's waiting link stops working.
    $token = inviteByLink($this->owner, $noEmail);
    inOrganization($this->acme, fn () => $noEmail->delete());
    openPortalInvitation($token)->assertNotFound();

    expect(portalInvitationsOf($this->acme))->toHaveCount(2);
});

it('limits invitation sends per tenant, per member and per organization, and the links per address', function () {
    // Per tenant: 5 a day (first send + 4 more), then refused.
    inviteByLink($this->owner, $this->tenant);
    foreach (range(1, 4) as $ignored) {
        portalCall($this->owner, $this->tenant, 'resend', ['link'])->assertOk()->assertSee('The old one no longer works');
    }
    portalCall($this->owner, $this->tenant, 'resend', ['link'])->assertOk()->assertSee('Too many invitations');
    expect(portalInvitationsOf($this->acme)[0]->send_count)->toBe(5);

    // Per member (30 an hour) and per organization (50 an hour), counted across tenants.
    $second = tenantIn($this->acme, ['phone' => '+2348035550010']);
    RateLimiter::clear('tenant-invitations:tenant:'.$second->id);
    foreach (range(1, 30) as $ignored) {
        RateLimiter::hit('tenant-invitations:user:'.$this->owner->id, 3600);
    }
    portalCall($this->owner, $second, 'invite', ['link'])->assertOk()->assertSee('Too many invitations');

    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    foreach (range(1, 50) as $ignored) {
        RateLimiter::hit('tenant-invitations:organization:'.$this->acme->id, 3600);
    }
    portalCall($admin, $second, 'invite', ['link'])->assertOk()->assertSee('Too many invitations');
    expect(portalInvitationsOf($this->acme))->toHaveCount(1);

    // The public links: 30 openings a minute per IP, then 429.
    foreach (range(1, 30) as $ignored) {
        openPortalInvitation(str_repeat('c', 48))->assertNotFound();
    }
    openPortalInvitation(str_repeat('c', 48))->assertStatus(429);
});
