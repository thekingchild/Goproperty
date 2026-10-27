<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Audit\AuthenticationLog;
use App\Enums\InvitationChannel;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Enums\PhoneVerificationMethod;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/helpers.php';

/*
 * P03-01b: the agency's one-time sign-in link (ONBOARDING_COMPLIANCE.md § 2,
 * P03 decision 4): a Core invitation of kind `sign_in_link`, 24 hours,
 * single use, audited, for a phone-only tenant's account and nothing more.
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner']), $this->acme);
    $this->tenant = tenantIn($this->acme, ['first_name' => 'Musa', 'last_name' => 'Bello', 'email' => null, 'phone' => '+2348031112222']);
    $this->account = portalAccountFor($this->acme, $this->tenant);
    $this->tenant = freshTenant($this->acme, $this->tenant);
});

afterEach(fn () => tenantPagesFreshRequest());

it('acceptance: a one-time sign-in link signs the tenant in once, may set a new password, and dies after use', function () {
    $token = signInLinkByLink($this->owner, $this->tenant);

    [$link] = portalInvitationsOf($this->acme, InvitationKind::SignInLink);
    expect($link->user_id)->toBe($this->account->id)
        ->and($link->tenant_profile_id)->toBe($this->tenant->id)
        ->and($link->channel)->toBe(InvitationChannel::Link)
        ->and(abs((int) $link->expires_at?->diffInSeconds(now()->addHours(24))))->toBeLessThan(5)
        ->and($link->token_hash)->toBe(hash('sha256', $token))
        ->and(AuthenticationLog::query()->where('event', 'agency_signin_link_created')->where('authenticatable_id', $this->account->id)->count())->toBe(1)
        ->and(AuditEntry::query()->where('event', 'tenant.sign_in_link_created')->sole()->causer_id)->toBe($this->owner->id);

    // Opening it changes nothing (link previews cannot use it up).
    asBrowser()->get("/portal/sign-in/{$token}")->assertOk()->assertSee('Sign in, Musa')->assertSee('Acme Homes');
    asBrowser()->get("/portal/sign-in/{$token}")->assertOk();
    $this->assertGuest();

    // A short password is refused without using the link.
    asBrowser()->post("/portal/sign-in/{$token}", ['password' => 'short'])->assertSessionHasErrors('password');
    $this->assertGuest();

    asBrowser()->post("/portal/sign-in/{$token}", ['password' => 'a-brand-new-password'])->assertRedirect('/portal');
    $this->assertAuthenticatedAs($this->account);
    expect(Hash::check('a-brand-new-password', (string) $this->account->fresh()?->password))->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'tenant.sign_in_link_used')->sole()->getProperty('password_changed'))->toBeTrue()
        ->and(AuthenticationLog::query()->where('event', 'login_success')->where('authenticatable_id', $this->account->id)->count())->toBe(1)
        ->and(portalInvitationsOf($this->acme, InvitationKind::SignInLink)[0]->status)->toBe(InvitationStatus::Accepted);
    tenantPagesFreshRequest();
    $this->get('/portal')->assertOk();

    // Once only.
    asBrowser()->get("/portal/sign-in/{$token}")->assertNotFound()->assertSee('Sign-in links work once, for 24 hours');
    asBrowser()->post("/portal/sign-in/{$token}")->assertNotFound();
    $this->assertGuest();

    // Signing in normally with the new password lands on the portal.
    asBrowser()->post('/login', ['identifier' => '0803 111 2222', 'password' => 'a-brand-new-password'])->assertRedirect('/portal');
});

it('keeps the password when none is chosen', function () {
    $token = signInLinkByLink($this->owner, $this->tenant);
    asBrowser()->post("/portal/sign-in/{$token}", ['password' => ''])->assertRedirect('/portal');
    $this->assertAuthenticatedAs($this->account);
    expect(Hash::check('password', (string) $this->account->fresh()?->password))->toBeTrue();
});

it('kills a sign-in link after 24 hours', function () {
    $token = signInLinkByLink($this->owner, $this->tenant);

    $this->travel(24)->hours();
    $this->travel(-1)->minutes();
    asBrowser()->get("/portal/sign-in/{$token}")->assertOk();
    $this->travel(2)->minutes();
    asBrowser()->get("/portal/sign-in/{$token}")->assertNotFound();
    asBrowser()->post("/portal/sign-in/{$token}")->assertNotFound();
    $this->assertGuest();
    expect(portalInvitationsOf($this->acme, InvitationKind::SignInLink)[0]->status)->toBe(InvitationStatus::Expired);
});

it('replaces the previous sign-in link with a new one, and revokes one', function () {
    $first = signInLinkByLink($this->owner, $this->tenant);
    $second = signInLinkByLink($this->owner, $this->tenant);

    asBrowser()->get("/portal/sign-in/{$first}")->assertNotFound();
    asBrowser()->post("/portal/sign-in/{$first}")->assertNotFound();
    asBrowser()->get("/portal/sign-in/{$second}")->assertOk();
    expect(AuditEntry::query()->where('event', 'tenant.sign_in_link_created')->latest('id')->first()?->getProperty('replaced_invitation_ids'))->toHaveCount(1);

    tenantsPage($this->owner, "/{$this->tenant->id}")->assertSee('data-test="revoke-sign-in-link"', false);
    portalCall($this->owner, $this->tenant, 'revokeSignInLink')->assertOk()->assertSee('It no longer works');
    asBrowser()->post("/portal/sign-in/{$second}")->assertNotFound();
    $this->assertGuest();
    expect(collect(portalInvitationsOf($this->acme, InvitationKind::SignInLink))->pluck('status')->all())->toBe([InvitationStatus::Cancelled, InvitationStatus::Cancelled])
        ->and(AuditEntry::query()->where('event', 'tenant.sign_in_link_revoked')->count())->toBe(1);
    portalCall($this->owner, $this->tenant, 'revokeSignInLink')->assertOk()->assertSee('no sign-in link to revoke');
});

it('sends a sign-in link by the agency\'s WhatsApp, and opening it verifies the number', function () {
    portalCall($this->owner, $this->tenant, 'createSignInLink', ['whatsapp'])->assertOk()->assertSee('sent to the tenant by WhatsApp');
    $message = lastWhatsApp();
    expect($message->to)->toBe('+2348031112222')->and($message->event)->toBe('tenant_sign_in_link');

    asBrowser()->post('/portal/sign-in/'.linkToken($message->variables['sign_in_link']))->assertRedirect('/portal');
    $this->account->refresh();
    expect($this->account->phone_verification_method)->toBe(PhoneVerificationMethod::WhatsAppLink)
        ->and($this->account->phone_verified_at)->not->toBeNull();
});

it('refuses a sign-in link for an account the agency must not open', function (Closure $setUp, string $reason) {
    $setUp->call($this);
    $tenant = freshTenant($this->acme, $this->tenant);

    $page = tenantsPage($this->owner, "/{$tenant->id}")->assertDontSee('data-test="sign-in-link-link"', false);
    // The record says why (with no account at all it offers an invitation instead).
    $tenant->user_id !== null ? $page->assertSee($reason) : $page->assertSee('data-test="invite-link"', false);
    portalCall($this->owner, $tenant, 'createSignInLink', ['link'])->assertOk()->assertSee($reason);
    expect(portalInvitationsOf($this->acme, InvitationKind::SignInLink))->toBe([]);
})->with([
    'no portal account yet' => [function () {
        inOrganization($this->acme, fn () => $this->tenant->forceFill(['user_id' => null])->save());
    }, 'no portal account yet'],
    'an account with an email address' => [function () {
        $this->account->forceFill(['email' => 'musa@example.ng'])->save();
    }, 'reset their password themselves'],
    'two-factor authentication' => [function () {
        $this->account->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    }, 'two-factor authentication'],
    'a member of a workspace' => [function () {
        joinAs($this->account, Organization::factory()->create(), OrganizationRole::Agent);
    }, 'also works in an organization'],
    'a tenant of another organization' => [function () {
        $other = Organization::factory()->create();
        $elsewhere = tenantIn($other, ['phone' => '+2348031112222']);
        inOrganization($other, fn () => $elsewhere->forceFill(['user_id' => $this->account->id])->save());
    }, 'also a tenant of another organization'],
]);

it('checks again when the link is used: an account that changed since is not opened', function () {
    $token = signInLinkByLink($this->owner, $this->tenant);
    $this->account->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

    asBrowser()->get("/portal/sign-in/{$token}")->assertNotFound();
    asBrowser()->post("/portal/sign-in/{$token}")->assertNotFound();
    $this->assertGuest();

    // Unlinked from the tenant since: the link dies too.
    $this->account->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
    $again = signInLinkByLink($this->owner, $this->tenant);
    $someoneElse = User::factory()->create(['email' => null, 'phone' => '+2348039998888']);
    inOrganization($this->acme, fn () => $this->tenant->forceFill(['user_id' => $someoneElse->id])->save());
    asBrowser()->post("/portal/sign-in/{$again}")->assertNotFound();
    $this->assertGuest();
});

it('refuses someone signed in with another account, and lets the tenant themselves through', function () {
    $token = signInLinkByLink($this->owner, $this->tenant);
    $stranger = User::factory()->create();

    asBrowser($stranger)->get("/portal/sign-in/{$token}")->assertForbidden()->assertSee('This link is for another account');
    asBrowser($stranger)->post("/portal/sign-in/{$token}", ['password' => 'a-brand-new-password'])->assertForbidden();
    expect(portalInvitationsOf($this->acme, InvitationKind::SignInLink)[0]->status)->toBe(InvitationStatus::Pending)
        ->and(Hash::check('password', (string) $this->account->fresh()?->password))->toBeTrue();

    asBrowser($this->account)->post("/portal/sign-in/{$token}")->assertRedirect('/portal');
    expect(portalInvitationsOf($this->acme, InvitationKind::SignInLink)[0]->status)->toBe(InvitationStatus::Accepted);
});

it('limits sign-in links per tenant, per member and per organization', function () {
    foreach (range(1, 5) as $ignored) {
        signInLinkByLink($this->owner, $this->tenant);
    }
    portalCall($this->owner, $this->tenant, 'createSignInLink', ['link'])->assertOk()->assertSee('Too many sign-in links');
    expect(portalInvitationsOf($this->acme, InvitationKind::SignInLink))->toHaveCount(5);

    RateLimiter::clear('sign-in-links:tenant:'.$this->tenant->id);
    foreach (range(1, 20) as $ignored) {
        RateLimiter::hit('sign-in-links:user:'.$this->owner->id, 3600);
    }
    portalCall($this->owner, $this->tenant, 'createSignInLink', ['link'])->assertOk()->assertSee('Too many sign-in links');

    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    foreach (range(1, 30) as $ignored) {
        RateLimiter::hit('sign-in-links:organization:'.$this->acme->id, 3600);
    }
    portalCall($admin, $this->tenant, 'createSignInLink', ['link'])->assertOk()->assertSee('Too many sign-in links');
    expect(portalInvitationsOf($this->acme, InvitationKind::SignInLink))->toHaveCount(5);

    // Using links: 10 tries a minute per IP, then 429.
    foreach (range(1, 10) as $ignored) {
        asBrowser()->post('/portal/sign-in/'.str_repeat('d', 48))->assertNotFound();
    }
    asBrowser()->post('/portal/sign-in/'.str_repeat('d', 48))->assertStatus(429);
});
