<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Audit\AuthenticationLog;
use App\Auth\Devices\KnownDevices;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Members\MemberInvitations;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\MemberInvitation;
use App\Tenancy\OrganizationContext;
use App\Theme\ThemeContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../../Support/members.php';

/*
 * P01-04 acceptance: "An invited user with role `agent` sees only what
 * ARCHITECTURE.md § 5 grants; expired/used tokens rejected". Invitations
 * by email with a single-use, 14-day link (ONBOARDING_COMPLIANCE.md § 2);
 * acceptance sets a password and joins, or joins an account that signs in.
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner', 'email' => 'ngozi@acme.ng']), $this->acme);
});

afterEach(fn () => membersFreshRequest());

/** Each permission the user holds in the organization, by checking the whole catalogue. */
function memberHeldPermissions(User $user, Organization $organization): array
{
    return app(OrganizationContext::class)->run($organization, function () use ($user): array {
        $user = $user->fresh();
        $held = [];

        foreach (OrganizationPermission::cases() as $permission) {
            $viaPermission = $user->hasPermissionTo($permission->value, OrganizationPermission::GUARD);
            $viaGate = Gate::forUser($user)->allows($permission->value);
            expect($viaGate)->toBe($viaPermission, "Gate and permission disagree on {$permission->value}");

            if ($viaPermission) {
                $held[] = $permission->value;
            }
        }

        sort($held);

        return $held;
    });
}

it('gives an invited and accepted agent exactly what ARCHITECTURE.md § 5 grants an agent, and refuses the rest', function () {
    $token = inviteMember($this->owner, 'chidi@acme.ng', ['agent']);

    // The guest sets a name, a mobile number and a password, is signed in and lands in the workspace.
    openInvitation($token)->assertOk()->assertSee('Join Acme Homes')->assertSee('Ngozi Owner')->assertSee('Agent')->assertSee('Choose a password');
    acceptAsNewPerson($token)->assertRedirect('/o/acme-homes');

    $agent = User::query()->where('email', 'chidi@acme.ng')->firstOrFail();
    $this->assertAuthenticatedAs($agent);
    expect($agent->roleNamesIn($this->acme))->toBe(['agent'])
        ->and($agent->email_verified_at)->not->toBeNull()
        ->and($agent->phone)->toBe('+2348035550101')
        ->and($agent->belongsToOrganization($this->other))->toBeFalse();

    // The permission catalogue, permission by permission: exactly the agent's grants.
    $expected = array_map(fn (OrganizationPermission $p): string => $p->value, OrganizationRole::Agent->permissions());
    sort($expected);
    $refused = array_values(array_diff(OrganizationPermission::values(), $expected));
    expect(memberHeldPermissions($agent, $this->acme))->toBe($expected)
        ->and($refused)->toContain('members.view', 'members.manage', 'organization.update', 'organization.billing', 'organization.payment_settings', 'organization.delete', 'reports.view', 'imports.manage');

    // Pages: the workspace opens; the members page (members.view) does not; another organization is 404.
    membersFreshRequest();
    $this->get('/o/acme-homes')->assertOk();
    membersPage($agent)->assertForbidden();
    membersFreshRequest();
    $this->get('/o/other-realty')->assertNotFound();

    // The acceptance is in the authentication log and the audit log.
    expect(AuthenticationLog::query()->where('event', 'invitation_accepted')->where('authenticatable_id', $agent->id)->count())->toBe(1);
    $entry = AuditEntry::query()->where('log_name', 'members')->where('event', 'invitation.accepted')->firstOrFail();
    expect($entry->causer_id)->toBe($agent->id)
        ->and($entry->subject_id)->toBe($this->acme->id)
        ->and($entry->getProperty('roles'))->toBe(['agent']);
    $this->artisan('goproperty:audit-verify')->assertSuccessful();

    $invitation = app(OrganizationContext::class)->run($this->acme, fn () => Invitation::query()->firstOrFail());
    expect($invitation->status)->toBe(InvitationStatus::Accepted)
        ->and($invitation->accepted_by_user_id)->toBe($agent->id);
});

it('invites with several roles and an administrator joins with both', function () {
    $token = inviteMember($this->owner, 'bola@acme.ng', ['org-admin', 'agent']);
    acceptAsNewPerson($token, ['name' => 'Bola Admin', 'phone' => ''])->assertRedirect('/o/acme-homes');

    $admin = User::query()->where('email', 'bola@acme.ng')->firstOrFail();
    expect($admin->roleNamesIn($this->acme))->toBe(['agent', 'org-admin'])
        ->and($admin->phone)->toBeNull();
    membersPage($admin)->assertOk();
});

it('rejects expired, used and revoked links, and unknown ones, all the same way', function () {
    $used = inviteMember($this->owner, 'used@acme.ng');
    $expired = inviteMember($this->owner, 'late@acme.ng');
    $revoked = inviteMember($this->owner, 'revoked@acme.ng');

    acceptAsNewPerson($used)->assertRedirect('/o/acme-homes');
    $revokedRow = app(OrganizationContext::class)->run($this->acme, fn () => Invitation::query()->where('email', 'revoked@acme.ng')->firstOrFail());
    membersCall($this->owner, [['revoke', [$revokedRow->id]]])->assertOk();

    $this->travel(MemberInvitations::VALID_DAYS)->days();
    $this->travel(1)->minutes();

    foreach ([$used, $expired, $revoked, str_repeat('x', 48)] as $token) {
        openInvitation($token)->assertNotFound()->assertSee('This invitation link no longer works')->assertDontSee('Acme Homes');
        acceptAsNewPerson($token, ['name' => 'Someone Else'])->assertNotFound();
    }

    // A used link also fails for the account that used it.
    $member = User::query()->where('email', 'used@acme.ng')->firstOrFail();
    openInvitation($used, $member)->assertNotFound();
    membersFreshRequest();
    $this->actingAs($member)->post("/invitations/{$used}")->assertNotFound();

    expect(User::query()->where('name', 'Someone Else')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'late@acme.ng')->exists())->toBeFalse();
    $statuses = app(OrganizationContext::class)->run($this->acme, fn () => Invitation::query()->orderBy('email')->pluck('status', 'email')->map->value->all());
    expect($statuses)->toBe(['late@acme.ng' => 'expired', 'revoked@acme.ng' => 'cancelled', 'used@acme.ng' => 'accepted']);
});

it('still works on the last day of the 14', function () {
    $token = inviteMember($this->owner, 'chidi@acme.ng');
    $this->travel(MemberInvitations::VALID_DAYS)->days();
    $this->travel(-1)->minutes();

    acceptAsNewPerson($token)->assertRedirect('/o/acme-homes');
});

it('works once even when accepted twice at the same time', function () {
    $token = inviteMember($this->owner, 'chidi@acme.ng');

    acceptAsNewPerson($token)->assertRedirect('/o/acme-homes');
    acceptAsNewPerson($token, ['name' => 'Second Try'])->assertNotFound();

    expect(User::query()->where('email', 'chidi@acme.ng')->count())->toBe(1);
});

it('makes the old link stop working when the invitation is sent again', function () {
    $first = inviteMember($this->owner, 'chidi@acme.ng');
    $invitation = app(OrganizationContext::class)->run($this->acme, fn () => Invitation::query()->firstOrFail());

    $this->travel(15)->days();
    membersCall($this->owner, [['resend', [$invitation->id]]])->assertOk()->assertSee('again, with a new link');
    $second = memberInvitationToken('chidi@acme.ng');

    expect($second)->not->toBe($first);
    openInvitation($first)->assertNotFound();
    openInvitation($second)->assertOk();
    expect($invitation->fresh()->send_count)->toBe(2)
        ->and($invitation->fresh()->expires_at->isAfter(now()->addDays(13)))->toBeTrue();
});

it('binds the invitation to its address: another signed-in account is refused', function () {
    $token = inviteMember($this->owner, 'chidi@acme.ng');
    $stranger = User::factory()->create(['email' => 'mallory@example.com']);

    openInvitation($token, $stranger)->assertForbidden()->assertSee('This invitation is for another account')->assertDontSee('chidi@acme.ng');
    membersFreshRequest();
    $this->actingAs($stranger)->post("/invitations/{$token}")->assertForbidden();

    expect($stranger->belongsToOrganization($this->acme))->toBeFalse();
    // The link still works for the right person.
    openInvitation($token)->assertOk();
});

it('lets an existing account sign in and come back to join', function () {
    $existing = User::factory()->create(['email' => 'chidi@acme.ng', 'password' => 'a-long-password-1']);
    joinAs($existing, $this->other, OrganizationRole::Admin);
    $token = inviteMember($this->owner, 'Chidi@Acme.ng', ['agent']);

    // A guest posting the new-account form is sent back to sign in; nothing changes on the account.
    acceptAsNewPerson($token)->assertRedirect("/invitations/{$token}");
    expect($existing->fresh()->name)->toBe($existing->name);

    openInvitation($token)->assertOk()->assertSee('Sign in to accept')->assertDontSee('Choose a password');

    membersFreshRequest();
    $this->post('/login', ['identifier' => 'chidi@acme.ng', 'password' => 'a-long-password-1'])->assertRedirect("/invitations/{$token}");

    membersFreshRequest();
    $this->get("/invitations/{$token}")->assertOk()->assertSee('Join Acme Homes')->assertSee('data-test="join-button"', false);
    membersFreshRequest();
    $this->post("/invitations/{$token}")->assertRedirect('/o/acme-homes');

    expect($existing->roleNamesIn($this->acme))->toBe(['agent'])
        ->and($existing->roleNamesIn($this->other))->toBe(['org-admin']);
    expect(AuthenticationLog::query()->where('event', 'invitation_accepted')->where('authenticatable_id', $existing->id)->exists())->toBeTrue();
});

it('uses the signup password rules', function () {
    $token = inviteMember($this->owner, 'chidi@acme.ng');

    acceptAsNewPerson($token, ['password' => 'short'])->assertSessionHasErrors('password');
    acceptAsNewPerson($token, ['phone' => '01 234 5678'])->assertSessionHasErrors('phone');
    acceptAsNewPerson($token, ['name' => ''])->assertSessionHasErrors('name');

    expect(User::query()->where('email', 'chidi@acme.ng')->exists())->toBeFalse();
    openInvitation($token)->assertOk();
});

it('does not store a mobile number another account uses, and says nothing about it', function () {
    User::factory()->create(['phone' => '+2348035550101']);
    $token = inviteMember($this->owner, 'chidi@acme.ng');

    acceptAsNewPerson($token)->assertRedirect('/o/acme-homes');

    expect(User::query()->where('email', 'chidi@acme.ng')->value('phone'))->toBeNull();
});

it('reveals nothing about accounts when inviting: the same reply and the same email', function () {
    User::factory()->create(['email' => 'has-account@example.com']);

    $withAccount = membersCall($this->owner, [['invite']], ['email' => 'has-account@example.com', 'roles' => ['agent']])->assertOk();
    $without = membersCall($this->owner, [['invite']], ['email' => 'no-account@example.com', 'roles' => ['agent']])->assertOk();

    foreach (['has-account@example.com' => $withAccount, 'no-account@example.com' => $without] as $address => $response) {
        expect(LivewireHttp::errors($response))->toBe([])
            ->and(LivewireHttp::html($response))->toContain("Invitation sent to {$address}. The link works for 14 days.");
    }

    $mails = Notification::sent(new AnonymousNotifiable, MemberInvitation::class)
        ->map(fn (MemberInvitation $n): array => Arr::except($n->toMail(new AnonymousNotifiable)->toArray(), ['actionUrl', 'displayableActionUrl']));
    expect($mails)->toHaveCount(2)
        ->and($mails[0])->toBe($mails[1]);
});

it('sends invitations by email only, queued on the notifications queue, never by SMS', function () {
    inviteMember($this->owner, 'chidi@acme.ng');

    Notification::assertSentOnDemand(MemberInvitation::class, function (MemberInvitation $notification, array $channels, AnonymousNotifiable $notifiable): bool {
        return $channels === ['mail']
            && array_keys($notifiable->routes) === ['mail']
            && $notification->queue === 'notifications'
            && $notification->afterCommit === true;
    });
    Notification::assertCount(1);

    $mail = Notification::sent(new AnonymousNotifiable, MemberInvitation::class)->first()->toMail(new AnonymousNotifiable);
    expect($mail->subject)->toBe('Ngozi Owner invited you to Acme Homes on '.app(ThemeContext::class)->current()->name())
        ->and(implode(' ', $mail->introLines))->toContain('Your role: Agent.')
        ->and(implode(' ', $mail->outroLines))->toContain('14 days');
});

it('stores only a hash of the token', function () {
    $token = inviteMember($this->owner, 'chidi@acme.ng');
    $row = DB::table('invitations')->first();

    expect($row->token_hash)->toBe(hash('sha256', $token))
        ->and(json_encode($row))->not->toContain($token);
});

it('signs the new member in from a browser another account has used', function () {
    $token = inviteMember($this->owner, 'chidi@acme.ng');
    $this->owner->forceFill(['password' => 'a-long-password-1'])->save();

    // The owner signs in from this browser first (shared office computer), then signs out.
    membersFreshRequest();
    $device = $this->post('/login', ['identifier' => 'ngozi@acme.ng', 'password' => 'a-long-password-1'])->getCookie(KnownDevices::COOKIE)?->getValue();
    expect($device)->toBeString();
    membersFreshRequest();
    $this->flushSession();

    $this->withCookie(KnownDevices::COOKIE, $device)->post("/invitations/{$token}", [
        'name' => 'Chidi Agent', 'password' => 'a-long-password-1',
    ])->assertRedirect('/o/acme-homes');

    $agent = User::query()->where('email', 'chidi@acme.ng')->firstOrFail();
    expect(UserDevice::query()->where('token_hash', hash('sha256', $device))->pluck('user_id')->sort()->values()->all())
        ->toBe([$this->owner->id, $agent->id]);
});
