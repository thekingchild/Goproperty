<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\PhoneVerificationMethod;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Modules\Tenants\Models\TenantProfile;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P03-01b: what an invitation or sign-in link can never do. A link proves
 * at most an inbox or a phone, never control of an account, so it never
 * opens or takes over an existing account; it reaches only its own
 * organization's tenant; tenants never become members of the workspace;
 * none of it works while GoProperty staff impersonate or in a suspended
 * organization.
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner']), $this->acme);
    $this->tenant = tenantIn($this->acme, ['first_name' => 'Adaeze', 'last_name' => 'Okafor', 'email' => 'adaeze@example.ng', 'phone' => '+2348031234567']);
});

afterEach(fn () => tenantPagesFreshRequest());

it('never opens an existing account from an emailed link: the account with that address signs in first', function () {
    $existing = joinAs(User::factory()->create(['email' => 'adaeze@example.ng', 'phone' => null]), $this->other, OrganizationRole::Agent);
    $token = inviteByEmail($this->owner, $this->tenant);

    // A guest holding the link only gets "Sign in to accept": no password form, no new account, no sign-in.
    openPortalInvitation($token)->assertOk()->assertSee('Sign in to accept')->assertDontSee('Choose a password');
    expect(session('url.intended'))->toBe(url("/portal/invitations/{$token}"));
    acceptPortalInvitation($token, null, ['name' => 'Attacker', 'password' => 'a-long-password-1'])->assertRedirect("/portal/invitations/{$token}");
    $this->assertGuest();
    expect(User::query()->where('email', 'adaeze@example.ng')->count())->toBe(1)
        ->and(Hash::check('password', (string) $existing->fresh()?->password))->toBeTrue()
        ->and(freshTenant($this->acme, $this->tenant)->user_id)->toBeNull();

    // Signed in, the account joins as a tenant; its other organization is untouched, and it never becomes an Acme member.
    openPortalInvitation($token, $existing)->assertOk()->assertSee('Add Acme Homes to my account');
    acceptPortalInvitation($token, $existing)->assertRedirect('/portal');
    expect(freshTenant($this->acme, $this->tenant)->user_id)->toBe($existing->id)
        ->and($existing->fresh()?->roleNamesIn($this->other))->toBe(['agent'])
        ->and($existing->fresh()?->belongsToOrganization($this->acme))->toBeFalse()
        ->and($existing->fresh()?->phone)->toBeNull();
});

it('never opens the account that has the tenant\'s phone number from a WhatsApp or shared link', function (string $channel) {
    $existing = User::factory()->create(['email' => 'someone@example.ng', 'phone' => '+2348031234567']);
    $token = $channel === 'whatsapp' ? inviteByWhatsApp($this->owner, $this->tenant) : inviteByLink($this->owner, $this->tenant);

    openPortalInvitation($token)->assertOk()->assertSee('Sign in to accept')->assertSee('phone number');
    acceptPortalInvitation($token)->assertRedirect("/portal/invitations/{$token}");
    $this->assertGuest();
    expect(User::query()->where('phone', '+2348031234567')->count())->toBe(1)
        ->and(Hash::check('password', (string) $existing->fresh()?->password))->toBeTrue()
        ->and(freshTenant($this->acme, $this->tenant)->user_id)->toBeNull();

    acceptPortalInvitation($token, $existing)->assertRedirect('/portal');
    $existing->refresh();
    expect(freshTenant($this->acme, $this->tenant)->user_id)->toBe($existing->id)
        ->and($existing->phone_verification_method)->toBe($channel === 'whatsapp' ? PhoneVerificationMethod::WhatsAppLink : null);
})->with(['whatsapp', 'link']);

it('refuses a signed-in account the link is not for, and changes nothing', function (string $channel, array $account) {
    $other = User::factory()->create($account);
    $token = match ($channel) {
        'email' => inviteByEmail($this->owner, $this->tenant),
        'whatsapp' => inviteByWhatsApp($this->owner, $this->tenant),
        default => inviteByLink($this->owner, $this->tenant),
    };

    openPortalInvitation($token, $other)->assertForbidden()->assertSee('This link is for another account');
    acceptPortalInvitation($token, $other)->assertForbidden();
    $other->refresh();
    expect(freshTenant($this->acme, $this->tenant)->user_id)->toBeNull()
        ->and($other->email)->toBe($account['email'])->and($other->phone)->toBe($account['phone'])
        ->and(portalInvitationsOf($this->acme)[0]->status)->toBe(InvitationStatus::Pending);
})->with([
    'email link, another address' => ['email', ['email' => 'not-adaeze@example.ng', 'phone' => null]],
    'WhatsApp link, another number' => ['whatsapp', ['email' => 'w@example.ng', 'phone' => '+2348030000001']],
    'shared link, another number' => ['link', ['email' => 'l@example.ng', 'phone' => '+2348030000002']],
]);

it('lets a signed-in account without that address or number join, and gain it when nobody else holds it', function () {
    // An account with an email but no phone, invited by WhatsApp: it gains the number, verified.
    $member = User::factory()->create(['email' => 'adaeze.work@example.ng', 'phone' => null]);
    acceptPortalInvitation(inviteByWhatsApp($this->owner, $this->tenant), $member)->assertRedirect('/portal');
    $member->refresh();
    expect($member->phone)->toBe('+2348031234567')
        ->and($member->phone_verification_method)->toBe(PhoneVerificationMethod::WhatsAppLink)
        ->and(freshTenant($this->acme, $this->tenant)->user_id)->toBe($member->id);

    // A phone-only account invited by email: it gains the address, confirmed.
    $second = tenantIn($this->acme, ['email' => 'musa@example.ng', 'phone' => '+2348037770000']);
    $phoneOnly = User::factory()->create(['email' => null, 'email_verified_at' => null, 'phone' => '+2348036660000']);
    acceptPortalInvitation(inviteByEmail($this->owner, $second), $phoneOnly)->assertRedirect('/portal');
    $phoneOnly->refresh();
    expect($phoneOnly->email)->toBe('musa@example.ng')->and($phoneOnly->email_verified_at)->not->toBeNull()
        ->and($phoneOnly->phone)->toBe('+2348036660000');
});

it('gives a new account only what the link proves: an emailed link never takes a number another account has', function () {
    User::factory()->create(['email' => 'holder@example.ng', 'phone' => '+2348031234567']);

    acceptPortalInvitation(inviteByEmail($this->owner, $this->tenant))->assertRedirect('/portal/welcome');
    $user = User::query()->where('email', 'adaeze@example.ng')->sole();
    expect($user->phone)->toBeNull()
        ->and(User::query()->where('phone', '+2348031234567')->sole()->email)->toBe('holder@example.ng');
});

it('cross-org: an invitation or sign-in link of one organization never touches another organization\'s tenant', function () {
    $outsider = joinAs(User::factory()->create(), $this->other);
    $theirs = tenantIn($this->other, ['first_name' => 'Other', 'email' => 'adaeze@example.ng', 'phone' => '+2348031234567']);

    // An Acme invitation pointed at the other organization's tenant (a forged row) finds nobody.
    $token = inviteByLink($this->owner, $this->tenant);
    DB::table('invitations')->where('token_hash', hash('sha256', $token))->update(['tenant_profile_id' => $theirs->id]);
    openPortalInvitation($token)->assertNotFound();
    acceptPortalInvitation($token)->assertNotFound();
    expect(freshTenant($this->other, $theirs)->user_id)->toBeNull();
    DB::table('invitations')->where('token_hash', hash('sha256', $token))->update(['tenant_profile_id' => $this->tenant->id]);

    // The real one links Acme's tenant only, though the other organization has the same person on file.
    acceptPortalInvitation($token)->assertRedirect('/portal/welcome');
    $user = User::query()->where('phone', '+2348031234567')->sole();
    expect(freshTenant($this->acme, $this->tenant)->user_id)->toBe($user->id)
        ->and(freshTenant($this->other, $theirs)->user_id)->toBeNull();

    // The other organization's members cannot see or act on Acme's tenant: the record and every action answer 404.
    tenantsPage($outsider, "/{$this->tenant->id}", 'other-realty')->assertNotFound();
    $panel = portalPanel($this->owner, $this->tenant);
    tenantPagesFreshRequest();
    LivewireHttp::update(test()->actingAs($outsider), $panel, [], [['createSignInLink', ['link']]])->assertNotFound();
    $ownPanel = portalPanel($outsider, $theirs, 'other-realty');
    $forged = json_decode($ownPanel, true);
    $forged['data']['tenantId'] = $this->tenant->id;
    tenantPagesFreshRequest();
    config(['app.debug' => false]);
    LivewireHttp::update(test()->actingAs($outsider), (string) json_encode($forged), [], [['createSignInLink', ['link']]])->assertStatus(419);

    // The tenant is a tenant of Acme only; an Acme sign-in link opens only Acme's tenant's account.
    $link = signInLinkByLink($this->owner, freshTenant($this->acme, $this->tenant));
    expect(portalInvitationsOf($this->other))->toBe([])
        ->and(inOrganization($this->other, fn () => Invitation::query()->count()))->toBe(0);
    asBrowser()->post("/portal/sign-in/{$link}")->assertRedirect('/portal');
    $this->assertAuthenticatedAs($user);
});

it('never makes a tenant a member: no organization_user row, no /o/{slug}, and they land on the portal', function () {
    acceptPortalInvitation(inviteByWhatsApp($this->owner, $this->tenant))->assertRedirect('/portal/welcome');
    $user = User::query()->where('phone', '+2348031234567')->sole();

    expect(DB::table('organization_user')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('model_has_roles')->where('model_id', $user->id)->where('model_type', $user->getMorphClass())->exists())->toBeFalse()
        ->and($user->workspaceOrganizationIds())->toBe([])
        ->and(inOrganization($this->acme, fn () => Membership::query()->where('user_id', $user->id)->exists()))->toBeFalse();

    tenantPagesFreshRequest();
    $this->actingAs($user)->get('/o/acme-homes')->assertNotFound();
    $this->get('/o/acme-homes/tenants')->assertNotFound();
    $this->get('/dashboard')->assertRedirect('/portal');
    $this->post('/organizations/acme-homes/switch')->assertNotFound();

    asBrowser();
    $this->post('/login', ['identifier' => '0803 123 4567', 'password' => 'a-long-password-1'])->assertRedirect('/portal');
});

it('refuses every portal-access action while GoProperty staff impersonate, and the links do not open in that session', function (ImpersonationMode $mode) {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Kemi Support', 'email' => 'kemi@goproperty.ng']);
    $token = inviteByLink($this->owner, $this->tenant);
    $linked = tenantIn($this->acme, ['email' => null, 'phone' => '+2348038880000']);
    portalAccountFor($this->acme, $linked);
    $linked = freshTenant($this->acme, $linked);

    $handOff = startSupport($staff, $this->owner, $this->acme, $mode);
    enterSupport($handOff)->assertRedirect('/o/acme-homes');

    foreach ([[$this->tenant, 'invite', ['link']], [$this->tenant, 'resend', ['link']], [$this->tenant, 'revoke', []], [$linked, 'createSignInLink', ['link']], [$linked, 'revokeSignInLink', []], [$linked, 'confirmPhone', []]] as [$tenant, $method, $params]) {
        $page = asSupport()->get("/o/acme-homes/tenants/{$tenant->id}")->assertOk()
            ->assertDontSee('data-test="resend-link"', false)->assertDontSee('data-test="sign-in-link-link"', false)->assertDontSee('data-test="confirm-phone"', false);
        $html = (string) $page->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        $panel = collect($matches[1])->map(fn (string $raw): string => html_entity_decode($raw, ENT_QUOTES | ENT_HTML5))
            ->first(fn (string $snapshot): bool => (json_decode($snapshot, true)['memo']['name'] ?? null) === 'tenants::tenant-portal-access');
        expect($panel)->toBeString();
        freshRequest();
        LivewireHttp::update(test(), (string) $panel, [], [[$method, $params]])->assertForbidden();
    }

    // The links themselves sign in and link accounts: refused in a support session.
    asSupport()->get("/portal/invitations/{$token}")->assertForbidden();
    asSupport()->post("/portal/invitations/{$token}")->assertForbidden();

    app(OrganizationContext::class)->run($this->acme, function () use ($linked): void {
        expect(Gate::forUser($this->owner)->allows('managePortalAccess', $linked))->toBeFalse();
    });
    expect(portalInvitationsOf($this->acme))->toHaveCount(1)
        ->and(portalInvitationsOf($this->acme)[0]->send_count)->toBe(1)
        ->and(portalInvitationsOf($this->acme)[0]->status)->toBe(InvitationStatus::Pending)
        ->and(User::query()->where('phone', '+2348038880000')->sole()->phone_verified_at)->toBeNull()
        ->and(AuditEntry::query()->where('event', 'impersonation.blocked')->where('subject_id', $handOff->session->id)->count())->toBeGreaterThanOrEqual(8)
        ->and(OrganizationPermission::ManageTenantPortalAccess->blockedWhileImpersonating())->toBeTrue();
})->with(['read-only' => ImpersonationMode::ReadOnly, 'write mode' => ImpersonationMode::Write]);

it('changes no portal access in a suspended organization, and its links link nobody', function () {
    $token = inviteByLink($this->owner, $this->tenant);
    $linked = tenantIn($this->acme, ['email' => null, 'phone' => '+2348038880000']);
    portalAccountFor($this->acme, $linked);
    $linked = freshTenant($this->acme, $linked);
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    tenantsPage($this->owner, "/{$this->tenant->id}")->assertOk()->assertDontSee('data-test="resend-link"', false);
    portalCall($this->owner, $this->tenant, 'resend', ['link'])->assertForbidden();
    portalCall($this->owner, $this->tenant, 'revoke')->assertForbidden();
    portalCall($this->owner, $linked, 'createSignInLink', ['link'])->assertForbidden();
    portalCall($this->owner, $linked, 'confirmPhone')->assertForbidden();

    openPortalInvitation($token)->assertOk();
    acceptPortalInvitation($token)->assertForbidden();
    expect(freshTenant($this->acme, $this->tenant)->user_id)->toBeNull()
        ->and(portalInvitationsOf($this->acme)[0]->status)->toBe(InvitationStatus::Pending)
        ->and(OrganizationPermission::ManageTenantPortalAccess->allowedWhileSuspended())->toBeFalse();
});

it('lets owners, administrators and agents manage portal access (ARCHITECTURE.md § 5), nobody else', function (OrganizationRole $role, bool $allowed) {
    $user = joinAs(User::factory()->create(), $this->acme, $role);
    $archived = tenantIn($this->acme, ['phone' => '+2348035550002']);
    inOrganization($this->acme, fn () => $archived->delete());

    inOrganization($this->acme, function () use ($user, $allowed, $archived): void {
        expect(Gate::forUser($user)->allows('managePortalAccess', $this->tenant))->toBe($allowed)
            ->and(Gate::forUser($user)->allows('managePortalAccess', TenantProfile::query()->withTrashed()->findOrFail($archived->id)))->toBeFalse()
            ->and($user->hasPermissionTo(OrganizationPermission::ManageTenantPortalAccess->value, OrganizationPermission::GUARD))->toBe($allowed);
    });

    if ($allowed) {
        expect(inviteByLink($user, $this->tenant))->toHaveLength(48);
    }
})->with([
    'org-owner' => [OrganizationRole::Owner, true],
    'org-admin' => [OrganizationRole::Admin, true],
    'agent' => [OrganizationRole::Agent, true],
    'property-owner' => [OrganizationRole::PropertyOwner, false],
    'tenant' => [OrganizationRole::Tenant, false],
]);

it('keeps tenant invitations and sign-in links out of Settings → Members and the member invitation page', function () {
    $token = inviteByLink($this->owner, $this->tenant);

    tenantPagesFreshRequest();
    $this->actingAs($this->owner)->get('/o/acme-homes/settings/members')->assertOk()->assertDontSee('+2348031234567')->assertDontSee('adaeze@example.ng');
    asBrowser()->get("/invitations/{$token}")->assertNotFound();
    asBrowser()->get("/portal/sign-in/{$token}")->assertNotFound();
    expect(portalInvitationsOf($this->acme, InvitationKind::Tenant))->toHaveCount(1);
});
