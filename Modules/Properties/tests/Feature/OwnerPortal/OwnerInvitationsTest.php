<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Audit\AuthenticationLog;
use App\Enums\InvitationChannel;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\PhoneVerificationMethod;
use App\Members\MemberInvitations;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\MemberInvitation;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Properties\Notifications\OwnerPortalInvitation;
use Modules\Properties\OwnerPortal\OwnerInvitations;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../../tests/Support/impersonation.php';

/*
 * P03-15: owner invitations (on Core invitations, kind `owner`, roles
 * `[property-owner]`; the tenant invitations' design, P03-01b). The agency
 * invites from the owner's page by email, by its WhatsApp (the log
 * stand-in, decision 3) or with a link / QR code; the owner opens the
 * link, sets a password and lands in the owner portal, never in a
 * workspace. Inviting needs `owners.manage`; never while staff
 * impersonate; used and expired links are rejected.
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com']), $this->acme);
    $this->admin = joinAs(User::factory()->create(['name' => 'Kunle Admin', 'email' => 'kunle.admin@example.com']), $this->acme, OrganizationRole::Admin);
    $this->agent = joinAs(User::factory()->create(['name' => 'Bola Agent', 'email' => 'bola.agent@example.com']), $this->acme, OrganizationRole::Agent);
    $this->otherOwner = joinAs(User::factory()->create(['name' => 'Chidi Owner', 'email' => 'chidi.owner@example.com']), $this->other);

    $this->bisi = ownerIn($this->acme, ['type' => 'person', 'first_name' => 'Bisi', 'last_name' => 'Adeyinka', 'email' => 'bisi.adeyinka@example.com', 'phone' => '+2348052223333']);
    $this->sunrise = buildingIn($this->acme, ['name' => 'Sunrise Court', 'slug' => 'sunrise-court', 'address' => '5 Admiralty Way, Lekki', 'owner_profile_id' => $this->bisi->id]);
    unitIn($this->acme, $this->sunrise, ['unit_label' => 'Flat 1']);
});

afterEach(fn () => ownerAccessFreshRequest());

describe('acceptance: each invite channel produces a working one-time link', function () {
    it('sets up an account from an emailed link, once: property-owner only, no workspace, the buildings in /owner', function () {
        $token = inviteOwnerByEmail($this->owner, $this->bisi);

        Notification::assertSentOnDemand(OwnerPortalInvitation::class, function (OwnerPortalInvitation $notification, array $channels, AnonymousNotifiable $notifiable): bool {
            expect($channels)->toBe(['mail'])->and($notification->queue)->toBe('notifications')
                ->and($notification->toMail($notifiable)->subject)->toContain('Adeyemi Realty Ltd');

            return $notifiable->routes['mail'] === 'bisi.adeyinka@example.com';
        });

        openOwnerInvitation($token)->assertOk()->assertSee('Welcome, Bisi')->assertSee('Ada Owner')->assertSee('Adeyemi Realty Ltd')
            ->assertSee('bisi.adeyinka@example.com')->assertSee('Choose a password');
        acceptOwnerInvitation($token)->assertRedirect('/owner/welcome');

        $user = User::query()->where('email', 'bisi.adeyinka@example.com')->sole();
        $this->assertAuthenticatedAs($user);
        expect($user->email_verified_at)->not->toBeNull()
            ->and($user->phone)->toBe('+2348052223333')
            ->and($user->phone_verified_at)->toBeNull()
            ->and(freshOwner($this->acme, $this->bisi)->user_id)->toBe($user->id)
            ->and($user->roleNamesIn($this->acme))->toBe([OrganizationRole::PropertyOwner->value])
            ->and($user->workspaceOrganizationIds())->toBe([]);

        ownerAccessFreshRequest();
        $this->get('/owner/welcome')->assertOk()->assertSee('Add a passkey')->assertSee('Go to my properties');
        $this->get('/owner')->assertOk()->assertSee('Sunrise Court')->assertSee('Adeyemi Realty');
        $this->get('/o/acme-homes')->assertNotFound();
        $this->get('/dashboard')->assertRedirect('/owner');

        [$invitation] = ownerInvitationsOf($this->acme);
        expect($invitation->kind)->toBe(InvitationKind::Owner)
            ->and($invitation->channel)->toBe(InvitationChannel::Email)
            ->and($invitation->status)->toBe(InvitationStatus::Accepted)
            ->and($invitation->accepted_by_user_id)->toBe($user->id)
            ->and($invitation->owner_profile_id)->toBe($this->bisi->id)
            ->and($invitation->roles)->toBe([OrganizationRole::PropertyOwner->value])
            ->and(AuthenticationLog::query()->where('event', 'invitation_accepted')->where('authenticatable_id', $user->id)->count())->toBe(1);
        $entry = AuditEntry::query()->where('event', 'owner.portal_access_granted')->sole();
        expect($entry->causer_id)->toBe($user->id)->and($entry->subject_id)->toBe($this->bisi->id)->and($entry->getProperty('channel'))->toBe('email');
        expect(AuditEntry::query()->where('event', 'owner.portal_invited')->sole()->causer_id)->toBe($this->owner->id);
        $this->artisan('goproperty:audit-verify')->assertSuccessful();

        // The link worked once.
        openOwnerInvitation($token)->assertNotFound()->assertSee('This link no longer works');
        acceptOwnerInvitation($token)->assertNotFound();
        expect(User::query()->where('email', 'bisi.adeyinka@example.com')->count())->toBe(1);

        // Signing in later lands on /owner.
        ownerBrowser();
        $this->post('/login', ['identifier' => 'bisi.adeyinka@example.com', 'password' => 'a-long-password-1'])->assertRedirect('/owner');
    });

    it('verifies the phone number of an account made from a WhatsApp link (whatsapp_link), once', function () {
        $token = inviteOwnerByWhatsApp($this->owner, $this->bisi);

        $message = lastOwnerWhatsApp();
        expect(ownerWhatsAppSent())->toHaveCount(1)
            ->and($message->to)->toBe('+2348052223333')
            ->and($message->event)->toBe('owner_invitation')
            ->and($message->text)->toContain('Adeyemi Realty Ltd')->toContain("/owner/invitations/{$token}");
        Notification::assertNothingSent();

        openOwnerInvitation($token)->assertOk()->assertSee('+234 ••• 3333')->assertDontSee('bisi.adeyinka@example.com');
        acceptOwnerInvitation($token)->assertRedirect('/owner/welcome');

        $user = User::query()->where('phone', '+2348052223333')->sole();
        expect($user->email)->toBeNull()
            ->and($user->phone_verified_at)->not->toBeNull()
            ->and($user->phone_verification_method)->toBe(PhoneVerificationMethod::WhatsAppLink)
            ->and(freshOwner($this->acme, $this->bisi)->user_id)->toBe($user->id);

        ownerAccessFreshRequest();
        $this->get('/owner')->assertOk()->assertSee('Sunrise Court');
        openOwnerInvitation($token)->assertNotFound();
        ownerBrowser();
        $this->post('/login', ['identifier' => '0805 222 3333', 'password' => 'a-long-password-1'])->assertRedirect('/owner');
    });

    it('shows a link and QR code once; only the hash is kept, and the phone stays unverified', function () {
        $response = ownerPanelCall($this->owner, $this->bisi, 'invite', ['link'])->assertOk()->assertSee('owner-portal-qr');
        $url = (string) ownerPanelState($response, 'shareUrl');
        $token = Str::afterLast($url, '/');

        expect($url)->toContain('/owner/invitations/')
            ->and(ownerInvitationsOf($this->acme)[0]->token_hash)->toBe(MemberInvitations::hash($token))
            ->and(json_encode(ownerInvitationsOf($this->acme)[0]->getAttributes()))->not->toContain($token);
        Notification::assertNothingSent();

        // Reloading the page does not show the link again.
        expect((string) ownersPage($this->owner, "/{$this->bisi->id}")->getContent())->not->toContain($token);

        acceptOwnerInvitation($token)->assertRedirect('/owner/welcome');
        $user = User::query()->where('phone', '+2348052223333')->sole();
        expect($user->phone_verified_at)->toBeNull()->and($user->email)->toBeNull();
        acceptOwnerInvitation($token)->assertNotFound();
    });
});

describe('acceptance: used or expired links rejected', function () {
    it('rejects expired, used, revoked and unknown links, all the same way', function () {
        $token = inviteOwnerByLink($this->owner, $this->bisi);

        $this->travel(OwnerInvitations::VALID_DAYS)->days();
        $this->travel(-1)->minutes();
        openOwnerInvitation($token)->assertOk();

        $this->travel(2)->minutes();
        $expired = openOwnerInvitation($token)->assertNotFound()->assertSee('This link no longer works');
        acceptOwnerInvitation($token)->assertNotFound();
        expect(ownerInvitationsOf($this->acme)[0]->status)->toBe(InvitationStatus::Expired);

        // Sent again: a new link; revoked: dead too.
        $fresh = Str::afterLast((string) ownerPanelState(ownerPanelCall($this->owner, $this->bisi, 'resend', ['link'])->assertOk(), 'shareUrl'), '/');
        openOwnerInvitation($fresh)->assertOk();
        ownerPanelCall($this->owner, $this->bisi, 'revoke')->assertOk()->assertSee('The invitation was revoked');
        $revoked = openOwnerInvitation($fresh)->assertNotFound();
        $unknown = openOwnerInvitation(Str::random(48))->assertNotFound();

        expect(strip_tags((string) $revoked->getContent()))->toBe(strip_tags((string) $unknown->getContent()))
            ->and(strip_tags((string) $expired->getContent()))->toBe(strip_tags((string) $unknown->getContent()))
            ->and(ownerInvitationsOf($this->acme)[0]->status)->toBe(InvitationStatus::Cancelled)
            ->and(freshOwner($this->acme, $this->bisi)->user_id)->toBeNull();
    });

    it('makes a new link on every send, by any channel, and kills the old one', function () {
        $first = inviteOwnerByEmail($this->owner, $this->bisi);
        $second = Str::afterLast((string) ownerPanelState(ownerPanelCall($this->owner, $this->bisi, 'resend', ['link'])->assertOk(), 'shareUrl'), '/');
        ownerPanelCall($this->owner, $this->bisi, 'resend', ['whatsapp'])->assertOk()->assertSee('The old link no longer works');
        $third = Str::afterLast(lastOwnerWhatsApp()->variables['invitation_link'], '/');

        openOwnerInvitation($first)->assertNotFound();
        openOwnerInvitation($second)->assertNotFound();
        openOwnerInvitation($third)->assertOk();

        [$invitation] = ownerInvitationsOf($this->acme);
        expect($invitation->send_count)->toBe(3)
            ->and($invitation->channel)->toBe(InvitationChannel::WhatsApp)
            ->and(count(ownerInvitationsOf($this->acme)))->toBe(1)
            ->and(AuditEntry::query()->where('event', 'owner.portal_invitation_resent')->count())->toBe(2);

        // One live invitation per owner.
        ownerPanelCall($this->owner, $this->bisi, 'invite', ['link'])->assertOk()->assertSee('already has an invitation');
    });

    it('refuses a channel the owner cannot be reached by, and an owner who already has access', function () {
        $phoneless = ownerIn($this->acme, ['type' => 'company', 'company_name' => 'Ikoyi Holdings Ltd', 'email' => null, 'phone' => null]);

        ownerPanelCall($this->owner, $phoneless, 'invite', ['link'])->assertOk()->assertSee('Add the owner&#039;s phone number first', false);
        ownerPanelCall($this->owner, $phoneless, 'invite', ['email'])->assertOk()->assertSee('Add the owner&#039;s email address first', false);
        expect(ownerInvitationsOf($this->acme))->toBe([]);

        acceptOwnerInvitation(inviteOwnerByLink($this->owner, $this->bisi))->assertRedirect('/owner/welcome');
        ownerPanel($this->owner, $this->bisi);
        ownerPanelCall($this->owner, $this->bisi, 'invite', ['email'])->assertOk()->assertSee('already has portal access');
    });
});

describe('sub: inviting needs owners.manage', function () {
    it('lets owners and administrators invite; agents, property owners and tenants never', function () {
        $ngozi = User::factory()->create(['name' => 'Ngozi Eze']);
        linkOwner($this->acme, ownerIn($this->acme, ['type' => 'person', 'first_name' => 'Ngozi', 'last_name' => 'Eze']), $ngozi);
        $tenant = joinAs(User::factory()->create(['name' => 'Tola Tenant']), $this->acme, OrganizationRole::Tenant);

        $abilities = fn (User $user): bool => inOrganization($this->acme, fn (): bool => Gate::forUser($user)->allows('managePortalAccess', freshOwner($this->acme, $this->bisi)));
        expect($abilities($this->owner))->toBeTrue()
            ->and($abilities($this->admin))->toBeTrue()
            ->and($abilities($this->agent))->toBeFalse()
            ->and($abilities($ngozi))->toBeFalse()
            ->and($abilities($tenant))->toBeFalse()
            ->and($abilities($this->otherOwner))->toBeFalse();

        // The administrator invites; the agent sees the section without its buttons and is refused if he sends the call by hand.
        inviteOwnerByLink($this->admin, $this->bisi);
        ownersPage($this->agent, "/{$this->bisi->id}")->assertOk()->assertSee('Owner portal')->assertDontSee('data-test="owner-resend-link"', false);
        foreach ([['invite', ['link']], ['resend', ['link']], ['revoke', []], ['removeAccess', []]] as [$method, $params]) {
            ownerPanelCall($this->agent, $this->bisi, $method, $params)->assertForbidden();
        }
        expect(ownerInvitationsOf($this->acme)[0]->send_count)->toBe(1)
            ->and(ownerInvitationsOf($this->acme)[0]->status)->toBe(InvitationStatus::Pending);

        // Property owners and tenants never open the owner's page; another organization's member gets 404.
        foreach ([$ngozi, $tenant] as $user) {
            ownerAccessFreshRequest();
            ownersPage($user, "/{$this->bisi->id}")->assertNotFound();
        }
        ownerAccessFreshRequest();
        ownersPage($this->otherOwner, "/{$this->bisi->id}", 'other-realty')->assertNotFound();
    });

    it('refuses a panel whose locked owner id is changed to another organization\'s owner', function () {
        config(['app.debug' => false]);
        $foreign = ownerIn($this->other, ['type' => 'person', 'first_name' => 'Foreign', 'last_name' => 'Owner', 'phone' => '+2348057770000']);
        $data = json_decode(ownerPanel($this->owner, $this->bisi), true);
        $data['data']['ownerId'] = $foreign->id;

        expect(LivewireHttp::update($this, (string) json_encode($data), [], [['invite', ['link']]])->status())->toBe(419)
            ->and(ownerInvitationsOf($this->other))->toBe([]);
    });

    it('changes no owner access in a suspended organization, and links nobody there', function () {
        $token = inviteOwnerByLink($this->owner, $this->bisi);
        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

        ownerPanelCall($this->owner, $this->bisi, 'resend', ['link'])->assertForbidden();
        ownerPanelCall($this->owner, $this->bisi, 'revoke')->assertForbidden();
        acceptOwnerInvitation($token)->assertForbidden();

        expect(User::query()->where('phone', '+2348052223333')->exists())->toBeFalse()
            ->and(freshOwner($this->acme, $this->bisi)->user_id)->toBeNull()
            ->and(ownerInvitationsOf($this->acme)[0]->status)->toBe(InvitationStatus::Pending);
    });
});

describe('sub: blocked while impersonating', function () {
    it('refuses every owner-access action while GoProperty staff impersonate, and the link does not open in that session', function (ImpersonationMode $mode) {
        $token = inviteOwnerByLink($this->owner, $this->bisi);
        $staff = staffMember();
        enterSupport(startSupport($staff, $this->owner, $this->acme, $mode));

        freshRequest();
        $page = asSupport()->get("/o/acme-homes/owners/{$this->bisi->id}")->assertOk();
        expect((string) $page->getContent())->not->toContain('data-test="owner-resend-link"')->not->toContain('data-test="owner-revoke-invitation"');
        preg_match_all('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $matches);
        $snapshot = collect($matches[1])->map(fn (string $raw): string => html_entity_decode($raw, ENT_QUOTES | ENT_HTML5))
            ->first(fn (string $snapshot): bool => (json_decode($snapshot, true)['memo']['name'] ?? null) === 'properties::owner-portal-access');

        foreach ([['invite', ['email']], ['resend', ['link']], ['revoke', []], ['removeAccess', []]] as [$method, $params]) {
            freshRequest();
            LivewireHttp::update(asSupport(), (string) $snapshot, [], [[$method, $params]])->assertForbidden();
        }

        freshRequest();
        asSupport()->get("/owner/invitations/{$token}")->assertForbidden();
        freshRequest();
        asSupport()->post("/owner/invitations/{$token}", ['name' => 'Someone', 'password' => 'a-long-password-1'])->assertForbidden();

        expect(inOrganization($this->acme, fn (): bool => Gate::forUser($this->owner)->allows('managePortalAccess', freshOwner($this->acme, $this->bisi))))->toBeFalse()
            ->and(ownerInvitationsOf($this->acme)[0]->send_count)->toBe(1)
            ->and(ownerInvitationsOf($this->acme)[0]->status)->toBe(InvitationStatus::Pending)
            ->and(AuditEntry::query()->where('event', 'impersonation.blocked')->count())->toBeGreaterThanOrEqual(4);
    })->with([ImpersonationMode::ReadOnly, ImpersonationMode::Write]);
});

describe('removing access, limits, and what stays apart', function () {
    it('removes an owner\'s access: no link, no role, no membership; invited again, they come back', function () {
        acceptOwnerInvitation(inviteOwnerByLink($this->owner, $this->bisi))->assertRedirect('/owner/welcome');
        $user = User::query()->where('phone', '+2348052223333')->sole();

        ownerPanelCall($this->owner, $this->bisi, 'removeAccess')->assertOk()->assertSee('no longer sees these buildings');

        expect(freshOwner($this->acme, $this->bisi)->user_id)->toBeNull()
            ->and($user->roleNamesIn($this->acme))->toBe([])
            ->and(inOrganization($this->acme, fn (): bool => Membership::query()->where('user_id', $user->id)->exists()))->toBeFalse()
            ->and(AuditEntry::query()->where('event', 'owner.portal_access_removed')->sole()->getProperty('membership_removed'))->toBeTrue();
        ownerPortalPage($user)->assertOk()->assertSee('No properties yet')->assertDontSee('Sunrise Court');
        ownerPortalPage($user, "/buildings/{$this->sunrise->id}")->assertNotFound();

        acceptOwnerInvitation(inviteOwnerByLink($this->owner, $this->bisi), $user)->assertRedirect('/owner');
        ownerPortalPage($user)->assertOk()->assertSee('Sunrise Court');
    });

    it('lets an owner be invited to work in the workspace too, and keeps those roles when the owner access is removed', function () {
        $bisi = User::factory()->create(['name' => 'Bisi Adeyinka', 'email' => 'bisi.adeyinka@example.com', 'phone' => '+2348052223333']);
        acceptOwnerInvitation(inviteOwnerByEmail($this->owner, $this->bisi), $bisi)->assertRedirect('/owner');

        // Settings → Members neither lists her nor counts her as a member: she may be invited as an agent.
        ownerAccessFreshRequest();
        $this->actingAs($this->owner)->get('/o/acme-homes/settings/members')->assertOk()->assertDontSee('bisi.adeyinka@example.com');
        inOrganization($this->acme, fn () => app(MemberInvitations::class)->invite($this->owner, 'bisi.adeyinka@example.com', [OrganizationRole::Agent]));
        $mail = Notification::sent(new AnonymousNotifiable, MemberInvitation::class)->last();
        assert($mail instanceof MemberInvitation);
        ownerBrowser($bisi)->post('/invitations/'.Str::afterLast($mail->url(), '/'))->assertRedirect('/o/acme-homes');

        expect($bisi->roleNamesIn($this->acme))->toBe([OrganizationRole::Agent->value, OrganizationRole::PropertyOwner->value]);
        ownerPanelCall($this->owner, $this->bisi, 'removeAccess')->assertOk();

        expect($bisi->roleNamesIn($this->acme))->toBe([OrganizationRole::Agent->value])
            ->and(inOrganization($this->acme, fn (): bool => Membership::query()->where('user_id', $bisi->id)->exists()))->toBeTrue()
            ->and(AuditEntry::query()->where('event', 'owner.portal_access_removed')->sole()->getProperty('membership_removed'))->toBeFalse();
        ownerAccessFreshRequest();
        $this->actingAs($bisi)->get('/o/acme-homes')->assertOk();
        ownerPortalPage($bisi)->assertOk()->assertDontSee('Sunrise Court');
    });

    it('limits invitation sends per owner, per member and per organization', function () {
        RateLimiter::clear('owner-invitations:owner:'.$this->bisi->id);
        inviteOwnerByLink($this->owner, $this->bisi);

        foreach (range(2, OwnerInvitations::PER_OWNER_DAILY) as $send) {
            ownerPanelCall($this->owner, $this->bisi, 'resend', ['link'])->assertOk()->assertSee('Here is a new link');
        }

        ownerPanelCall($this->owner, $this->bisi, 'resend', ['link'])->assertOk()->assertSee('Too many invitations have been sent');
        expect(ownerInvitationsOf($this->acme)[0]->send_count)->toBe(OwnerInvitations::PER_OWNER_DAILY);

        $this->travel(1)->days();
        $this->travel(1)->minutes();
        ownerPanelCall($this->owner, $this->bisi, 'resend', ['link'])->assertOk()->assertSee('Here is a new link');
    });

    it('never sends an SMS: only email and the agency\'s WhatsApp, and nothing leaves the server', function () {
        Http::fake();
        inviteOwnerByEmail($this->owner, $this->bisi);
        ownerPanelCall($this->owner, $this->bisi, 'resend', ['whatsapp'])->assertOk();

        Notification::assertSentOnDemand(OwnerPortalInvitation::class, fn (OwnerPortalInvitation $notification, array $channels): bool => $channels === ['mail']);
        expect(array_map(fn ($message): string => $message->event, ownerWhatsAppSent()))->toBe(['owner_invitation']);
        Http::assertNothingSent();

        $code = collect([...File::allFiles(module_path('Properties', 'app/OwnerPortal')), ...File::allFiles(module_path('Properties', 'app/Notifications'))])
            ->map(fn ($file): string => strtolower($file->getContents()))->implode("\n");
        expect($code)->not->toContain('gotext')->not->toContain('termii')->not->toContain('vonage')->not->toContain('twilio')->not->toContain("'sms'");
    });

    it('keeps owner invitations out of Settings → Members and the member invitation page', function () {
        $token = inviteOwnerByEmail($this->owner, $this->bisi);

        ownerAccessFreshRequest();
        $this->actingAs($this->owner)->get('/o/acme-homes/settings/members')->assertOk()->assertDontSee('bisi.adeyinka@example.com');
        ownerBrowser();
        $this->get("/invitations/{$token}")->assertNotFound();
        $this->get("/portal/invitations/{$token}")->assertNotFound();
        openOwnerInvitation($token)->assertOk();

        // The owner's pending invitation does not stop a member invitation to the same address.
        $member = inOrganization($this->acme, fn () => app(MemberInvitations::class)->invite($this->owner, 'bisi.adeyinka@example.com', [OrganizationRole::Agent]));
        expect($member->kind)->toBe(InvitationKind::User);
    });
});
