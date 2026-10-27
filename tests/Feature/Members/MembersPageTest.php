<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Members\MemberChangeRefused;
use App\Members\MemberInvitations;
use App\Members\Members;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\MemberInvitation;
use App\Platform\Customers\OrganizationSuspension;
use App\Platform\Impersonation\ImpersonationMode;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../../Support/members.php';
require_once __DIR__.'/../../Support/impersonation.php';

/*
 * Settings → Members (P01-04, ARCHITECTURE.md § 5): who may see it, who may
 * grant which role, the last owner, removal, and the central rules
 * (organization scope, suspended organizations, impersonation).
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner', 'email' => 'ngozi@acme.ng']), $this->acme);
    $this->admin = joinAs(User::factory()->create(['name' => 'Bola Admin', 'email' => 'bola@acme.ng']), $this->acme, OrganizationRole::Admin);
    $this->agent = joinAs(User::factory()->create(['name' => 'Chidi Agent', 'email' => 'chidi@acme.ng']), $this->acme, OrganizationRole::Agent);
});

afterEach(fn () => membersFreshRequest());

function memberMembershipOf(User $user, Organization $organization): Membership
{
    return app(OrganizationContext::class)->run($organization, fn () => Membership::query()->where('user_id', $user->id)->firstOrFail());
}

function memberInvitationsIn(Organization $organization): Collection
{
    return app(OrganizationContext::class)->run($organization, fn () => Invitation::query()->orderBy('id')->get());
}

/** Runs the callback inside the organization's context, as a request there would. */
function withinOrganization(Organization $organization, Closure $callback): mixed
{
    return app(OrganizationContext::class)->run($organization, $callback);
}

it('shows members and invitations to owners and administrators only', function () {
    inviteMember($this->owner, 'new@acme.ng');

    membersPage($this->owner)->assertOk()
        ->assertSee('Ngozi Owner')->assertSee('Bola Admin')->assertSee('Chidi Agent')
        ->assertSee('new@acme.ng')->assertSee('Invite someone');
    membersPage($this->admin)->assertOk()->assertSee('new@acme.ng')->assertSee('Invite someone');

    // Agents lack members.view; strangers and members of other organizations get 404.
    membersPage($this->agent)->assertForbidden();
    membersPage(User::factory()->create())->assertNotFound();
    membersPage(joinAs(User::factory()->create(), $this->other))->assertNotFound();
});

it('lets an owner grant any workspace role and an administrator every one but owner', function () {
    $ownerPage = membersPage($this->owner)->assertOk();
    foreach (['org-owner', 'org-admin', 'agent'] as $role) {
        $ownerPage->assertSee('data-test="invite-role-'.$role.'"', false);
    }

    membersPage($this->admin)->assertOk()
        ->assertSee('data-test="invite-role-org-admin"', false)
        ->assertSee('data-test="invite-role-agent"', false)
        ->assertDontSee('data-test="invite-role-org-owner"', false);

    // An administrator asking for org-owner anyway: refused, nothing stored or sent.
    membersCall($this->admin, [['invite']], ['email' => 'boss@acme.ng', 'roles' => ['org-owner']])->assertForbidden();
    membersCall($this->admin, [['invite']], ['email' => 'boss@acme.ng', 'roles' => ['agent', 'org-owner']])->assertForbidden();
    expect(fn () => withinOrganization($this->acme, fn () => app(MemberInvitations::class)->invite($this->admin, 'boss@acme.ng', [OrganizationRole::Owner])))
        ->toThrow(AuthorizationException::class);
    expect(memberInvitationsIn($this->acme))->toHaveCount(0);
    Notification::assertNothingSent();

    // An administrator invites administrators and agents; an owner invites owners.
    inviteMember($this->admin, 'second-admin@acme.ng', ['org-admin']);
    inviteMember($this->owner, 'co-owner@acme.ng', ['org-owner']);
    expect(memberInvitationsIn($this->acme)->pluck('roles')->all())->toBe([['org-admin'], ['org-owner']]);
});

it('refuses invitations from roles that cannot manage members, and roles outside the workspace', function () {
    expect(fn () => withinOrganization($this->acme, fn () => app(MemberInvitations::class)->invite($this->agent, 'x@acme.ng', [OrganizationRole::Agent])))
        ->toThrow(AuthorizationException::class);

    // Tenants and property owners have their own invitations (P03-01b, owner portal).
    membersCall($this->owner, [['invite']], ['email' => 'tenant@acme.ng', 'roles' => ['tenant']])->assertOk();
    membersCall($this->owner, [['invite']], ['email' => 'owner@acme.ng', 'roles' => ['property-owner']])->assertOk();
    expect(fn () => withinOrganization($this->acme, fn () => app(MemberInvitations::class)->invite($this->owner, 'tenant@acme.ng', [OrganizationRole::Tenant])))
        ->toThrow(MemberChangeRefused::class, 'Choose at least one role.');

    expect(memberInvitationsIn($this->acme))->toHaveCount(0);
    Notification::assertNothingSent();
});

it('refuses to invite a member or an address with an invitation waiting', function () {
    $response = membersCall($this->owner, [['invite']], ['email' => 'chidi@acme.ng', 'roles' => ['agent']])->assertOk();
    expect(LivewireHttp::errors($response)['email'][0] ?? '')->toContain('already a member');

    inviteMember($this->owner, 'new@acme.ng');
    $again = membersCall($this->owner, [['invite']], ['email' => 'NEW@acme.ng', 'roles' => ['agent']])->assertOk();
    expect(LivewireHttp::errors($again)['email'][0] ?? '')->toContain('already has an invitation waiting');

    expect(memberInvitationsIn($this->acme))->toHaveCount(1);
});

it('limits how many invitations go to one address', function () {
    inviteMember($this->owner, 'new@acme.ng');
    $invitation = memberInvitationsIn($this->acme)->first();

    for ($i = 1; $i < MemberInvitations::PER_ADDRESS_DAILY; $i++) {
        membersCall($this->owner, [['resend', [$invitation->id]]])->assertOk()->assertSee('again, with a new link');
    }

    $refused = membersCall($this->owner, [['resend', [$invitation->id]]])->assertOk();
    expect(LivewireHttp::html($refused))->toContain('Too many invitations');
    expect($invitation->fresh()->send_count)->toBe(MemberInvitations::PER_ADDRESS_DAILY);
});

it('cannot reach another organization\'s invitations or members (404)', function () {
    $otherOwner = joinAs(User::factory()->create(), $this->other);
    inviteMember($otherOwner, 'theirs@other.ng', slug: 'other-realty');
    $theirs = memberInvitationsIn($this->other)->first();
    $theirMember = memberMembershipOf($otherOwner, $this->other);

    membersCall($this->owner, [['resend', [$theirs->id]]])->assertNotFound();
    membersCall($this->owner, [['revoke', [$theirs->id]]])->assertNotFound();
    membersCall($this->owner, [['removeMember', [$theirMember->id]]])->assertNotFound();
    membersCall($this->owner, [['editMember', [$theirMember->id]]])->assertNotFound();
    membersPage($this->owner)->assertDontSee('theirs@other.ng');

    expect($theirs->fresh()->status)->toBe(InvitationStatus::Pending)
        ->and($theirs->fresh()->send_count)->toBe(1)
        ->and($otherOwner->belongsToOrganization($this->other))->toBeTrue();
});

it('revokes and resends through the page, audited', function () {
    inviteMember($this->admin, 'new@acme.ng', ['agent']);
    $invitation = memberInvitationsIn($this->acme)->first();

    membersCall($this->admin, [['resend', [$invitation->id]]])->assertOk();
    membersCall($this->admin, [['revoke', [$invitation->id]]])->assertOk()->assertSee('was revoked');
    expect($invitation->fresh()->status)->toBe(InvitationStatus::Cancelled);
    membersPage($this->owner)->assertDontSee('new@acme.ng');

    $events = AuditEntry::query()->where('log_name', 'members')->orderBy('id')->pluck('event')->all();
    expect($events)->toBe(['invitation.sent', 'invitation.resent', 'invitation.revoked']);
    expect(AuditEntry::query()->where('log_name', 'members')->pluck('causer_id')->unique()->all())->toBe([$this->admin->id]);

    // Revoked: cannot be revoked or sent again.
    expect(LivewireHttp::html(membersCall($this->admin, [['resend', [$invitation->id]]])->assertOk()))->toContain('already used or revoked');
});

it('lets an administrator never invite, change or remove an owner, nor revoke an owner\'s invitation', function () {
    inviteMember($this->owner, 'co-owner@acme.ng', ['org-owner']);
    $ownerInvite = memberInvitationsIn($this->acme)->first();
    $ownerMembership = memberMembershipOf($this->owner, $this->acme);

    membersCall($this->admin, [['resend', [$ownerInvite->id]]])->assertForbidden();
    membersCall($this->admin, [['revoke', [$ownerInvite->id]]])->assertForbidden();
    membersCall($this->admin, [['editMember', [$ownerMembership->id]]])->assertForbidden();
    membersCall($this->admin, [['removeMember', [$ownerMembership->id]]])->assertForbidden();

    // Promoting someone to owner, or themselves.
    $agentMembership = memberMembershipOf($this->agent, $this->acme);
    expect(fn () => withinOrganization($this->acme, fn () => app(Members::class)->changeRoles($this->admin, $agentMembership, [OrganizationRole::Owner])))->toThrow(AuthorizationException::class);
    expect(fn () => withinOrganization($this->acme, fn () => app(Members::class)->changeRoles($this->admin, memberMembershipOf($this->admin, $this->acme), [OrganizationRole::Owner])))->toThrow(AuthorizationException::class);

    expect($this->owner->roleNamesIn($this->acme))->toBe(['org-owner'])
        ->and($this->agent->roleNamesIn($this->acme))->toBe(['agent'])
        ->and($ownerInvite->fresh()->status)->toBe(InvitationStatus::Pending);

    // The agent's roles are the administrator's to change.
    $page = membersCall($this->admin, [['editMember', [$agentMembership->id]]])->assertOk();
    LivewireHttp::update(test()->actingAs($this->admin), LivewireHttp::next($page), ['editRoles' => ['org-admin']], [['saveRoles']])->assertOk()->assertSee('roles were changed');
    expect($this->agent->roleNamesIn($this->acme))->toBe(['org-admin']);
    $entry = AuditEntry::query()->where('event', 'member.roles_changed')->firstOrFail();
    expect($entry->getProperty('old'))->toBe(['roles' => ['agent']])
        ->and($entry->getProperty('attributes'))->toBe(['roles' => ['org-admin']]);
});

it('never leaves the organization without an owner', function () {
    $ownerMembership = memberMembershipOf($this->owner, $this->acme);

    $removed = membersCall($this->owner, [['removeMember', [$ownerMembership->id]]])->assertOk();
    expect(LivewireHttp::html($removed))->toContain('must keep at least one owner');

    $page = membersCall($this->owner, [['editMember', [$ownerMembership->id]]])->assertOk();
    $demoted = LivewireHttp::update(test()->actingAs($this->owner), LivewireHttp::next($page), ['editRoles' => ['org-admin']], [['saveRoles']])->assertOk();
    expect(LivewireHttp::errors($demoted)['editRoles'][0] ?? '')->toContain('must keep at least one owner');

    expect($this->owner->roleNamesIn($this->acme))->toBe(['org-owner'])
        ->and($this->owner->belongsToOrganization($this->acme))->toBeTrue()
        ->and(AuditEntry::query()->where('log_name', 'members')->exists())->toBeFalse();

    // With a second owner, the first may step down or leave.
    $second = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Owner);
    withinOrganization($this->acme, fn () => app(Members::class)->changeRoles($this->owner, $ownerMembership, [OrganizationRole::Admin]));
    expect($this->owner->roleNamesIn($this->acme))->toBe(['org-admin']);

    // Now the second owner is the last: they cannot remove themselves.
    expect(fn () => withinOrganization($this->acme, fn () => app(Members::class)->remove($second, memberMembershipOf($second, $this->acme))))
        ->toThrow(MemberChangeRefused::class);
    expect($second->roleNamesIn($this->acme))->toBe(['org-owner']);
});

it('removes a member with their roles in that organization only', function () {
    joinAs($this->agent, $this->other, OrganizationRole::Admin);
    // A direct permission in each organization too.
    withinOrganization($this->acme, fn () => $this->agent->givePermissionTo('reports.view'));
    withinOrganization($this->other, fn () => $this->agent->givePermissionTo('reports.view'));
    $rows = fn (string $table, Organization $organization): int => DB::table($table)->where('organization_id', $organization->id)->where('model_id', $this->agent->id)->where('model_type', $this->agent->getMorphClass())->count();
    expect($rows('model_has_roles', $this->acme))->toBe(1)
        ->and($rows('model_has_permissions', $this->acme))->toBe(1);

    membersCall($this->admin, [['removeMember', [memberMembershipOf($this->agent, $this->acme)->id]]])->assertOk()->assertSee('was removed');

    expect($rows('model_has_roles', $this->acme))->toBe(0)
        ->and($rows('model_has_permissions', $this->acme))->toBe(0)
        ->and($rows('model_has_roles', $this->other))->toBe(1)
        ->and($rows('model_has_permissions', $this->other))->toBe(1)
        ->and($this->agent->belongsToOrganization($this->acme))->toBeFalse()
        ->and($this->agent->roleNamesIn($this->other))->toBe(['org-admin']);

    // Gone from the workspace at the next request; the other one still opens.
    membersFreshRequest();
    $this->actingAs($this->agent)->get('/o/acme-homes')->assertNotFound();
    membersFreshRequest();
    $this->actingAs($this->agent)->get('/o/other-realty')->assertOk();

    $entry = AuditEntry::query()->where('event', 'member.removed')->firstOrFail();
    expect($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->getProperty('user_id'))->toBe($this->agent->id)
        ->and($entry->getProperty('old'))->toBe(['roles' => ['agent']]);
});

it('refuses every member change while the organization is suspended, and still shows the page', function () {
    inviteMember($this->owner, 'new@acme.ng');
    $invitation = memberInvitationsIn($this->acme)->first();
    $token = memberInvitationToken('new@acme.ng');
    seedStaffRoles();
    app(OrganizationSuspension::class)->suspend(staffNamed('Tunde', ['operations-manager']), $this->acme, 'Unpaid beyond grace');
    Notification::fake();

    membersPage($this->owner)->assertOk()->assertSee('new@acme.ng')->assertDontSee('Invite someone');

    $agentMembership = memberMembershipOf($this->agent, $this->acme);
    foreach ([
        [['invite']],
        [['resend', [$invitation->id]]],
        [['revoke', [$invitation->id]]],
        [['editMember', [$agentMembership->id]]],
        [['removeMember', [$agentMembership->id]]],
    ] as $calls) {
        membersCall($this->owner, $calls, ['email' => 'later@acme.ng', 'roles' => ['agent']])->assertForbidden();
    }

    // The services refuse too, and the link cannot be accepted.
    expect(fn () => withinOrganization($this->acme->fresh(), fn () => app(MemberInvitations::class)->invite($this->owner, 'later@acme.ng', [OrganizationRole::Agent])))->toThrow(AuthorizationException::class);
    expect(fn () => withinOrganization($this->acme->fresh(), fn () => app(Members::class)->remove($this->owner, $agentMembership)))->toThrow(AuthorizationException::class);
    acceptAsNewPerson($token)->assertForbidden();

    expect(memberInvitationsIn($this->acme))->toHaveCount(1)
        ->and($invitation->fresh()->status)->toBe(InvitationStatus::Pending)
        ->and($this->agent->belongsToOrganization($this->acme))->toBeTrue()
        ->and(User::query()->where('email', 'new@acme.ng')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('refuses every member change while GoProperty staff impersonate, in write mode too', function () {
    inviteMember($this->owner, 'new@acme.ng');
    $invitation = memberInvitationsIn($this->acme)->first();
    $token = memberInvitationToken('new@acme.ng');
    Notification::fake();
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    $agentMembership = memberMembershipOf($this->agent, $this->acme);

    $handOff = startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write);
    enterSupport($handOff)->assertRedirect('/o/acme-homes');

    $page = asSupport()->get('/o/acme-homes/settings/members')->assertOk()->assertDontSee('Invite someone');
    $snapshot = LivewireHttp::snapshot($page);
    foreach ([
        [['invite']],
        [['resend', [$invitation->id]]],
        [['revoke', [$invitation->id]]],
        [['editMember', [$agentMembership->id]]],
        [['saveRoles']],
        [['removeMember', [$agentMembership->id]]],
    ] as $calls) {
        freshRequest();
        LivewireHttp::update(test(), $snapshot, ['email' => 'later@acme.ng', 'roles' => ['agent'], 'editRoles' => ['org-admin']], $calls)->assertForbidden();
    }

    // The invitation link is refused in the support browser as well.
    asSupport()->get("/invitations/{$token}")->assertForbidden();
    asSupport()->post("/invitations/{$token}")->assertForbidden();

    expect(memberInvitationsIn($this->acme))->toHaveCount(1)
        ->and($invitation->fresh()->status)->toBe(InvitationStatus::Pending)
        ->and($invitation->fresh()->send_count)->toBe(1)
        ->and($this->agent->roleNamesIn($this->acme))->toBe(['agent']);
    Notification::assertNotSentTo(new AnonymousNotifiable, MemberInvitation::class);
    expect(AuditEntry::query()->where('log_name', 'impersonation')->where('event', 'impersonation.blocked')->count())->toBeGreaterThanOrEqual(8);
});
