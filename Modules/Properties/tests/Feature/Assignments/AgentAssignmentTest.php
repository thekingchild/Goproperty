<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Members\MemberRemoved;
use App\Members\Members;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Modules\Properties\Assignments\AgentAssignments;
use Modules\Properties\Enums\PropertyStatus;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../../tests/Support/impersonation.php';

/*
 * P02-03: assigning agents to buildings (DATABASE_SCHEMA.md § 3
 * `agent_building`, ARCHITECTURE.md § 5). Owners and administrators assign
 * the organization's agents on the building's page and take them off,
 * audited (Assignments\AgentAssignments, PropertyPolicy::assignAgents);
 * nobody else can. A member who leaves loses their assignments.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner']), $this->acme);
    $this->agent = joinAs(User::factory()->create(['name' => 'Ada Agent']), $this->acme, OrganizationRole::Agent);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->cedar = buildingIn($this->acme, ['name' => 'Cedar Gardens', 'slug' => 'cedar-gardens']);
});

afterEach(fn () => freshRequest());

it('lets owners and administrators assign the organization\'s agents to a building and take them off, audited', function (OrganizationRole $role) {
    $manager = $role === OrganizationRole::Owner ? $this->owner : joinAs(User::factory()->create(['name' => 'Bola Admin']), $this->acme, OrganizationRole::Admin);
    $second = joinAs(User::factory()->create(['name' => 'Chidi Agent']), $this->acme, OrganizationRole::Agent);

    $page = buildingsPage($manager, '/palm-court')->assertOk()
        ->assertSee('data-test="building-agents"', false)->assertSee('No agent is assigned to this building.')
        ->assertSee('data-test="agent-select"', false);
    $options = htmlOf($page);
    // Only agents are offered: owners and administrators see every building anyway.
    expect($options)->toContain('>Ada Agent</option>')->toContain('>Chidi Agent</option>')
        ->not->toContain('>Ngozi Owner</option>')->not->toContain('>Bola Admin</option>');

    $response = LivewireHttp::update($this, LivewireHttp::snapshot($page), ['agentToAssign' => (string) $this->agent->id], [['assignAgent']])->assertOk();
    expect(htmlOf($response))->toContain('Ada Agent now works on Palm Court')
        ->toContain('data-agent="'.$this->agent->id.'"')
        // An assigned agent is no longer offered.
        ->not->toContain('>Ada Agent</option>')->toContain('>Chidi Agent</option>');
    expect(assignmentsOf($this->acme)->all())->toBe([[$this->agent->id, $this->palm->id]]);

    $assigned = AuditEntry::query()->where('log_name', 'properties')->where('event', 'property.agent_assigned')->sole();
    expect($assigned->causer_id)->toBe($manager->id)
        ->and($assigned->subject_id)->toBe($this->palm->id)
        ->and($assigned->properties['user_id'])->toBe($this->agent->id);

    $response = LivewireHttp::update($this, LivewireHttp::next($response), [], [['unassignAgent', [$this->agent->id]]])->assertOk();
    expect(htmlOf($response))->toContain('The agent was taken off Palm Court.')->not->toContain('data-agent="')
        ->and(assignmentsOf($this->acme))->toBeEmpty();

    $unassigned = AuditEntry::query()->where('log_name', 'properties')->where('event', 'property.agent_unassigned')->sole();
    expect($unassigned->causer_id)->toBe($manager->id)
        ->and($unassigned->properties['user_id'])->toBe($this->agent->id);
})->with([
    'org-owner' => [OrganizationRole::Owner],
    'org-admin' => [OrganizationRole::Admin],
]);

it('grants properties.assign_agents to owners and administrators only, and classes it for the central guards', function () {
    $holders = fn (OrganizationPermission $permission): array => array_values(array_map(
        fn (OrganizationRole $role): string => $role->value,
        array_filter(OrganizationRole::cases(), fn (OrganizationRole $role): bool => in_array($permission, $role->permissions(), true)),
    ));

    expect($holders(OrganizationPermission::AssignAgents))->toBe(['org-owner', 'org-admin'])
        // Dropped while suspended; dropped while staff impersonate (it decides what a member sees).
        ->and(OrganizationPermission::AssignAgents->allowedWhileSuspended())->toBeFalse()
        ->and(OrganizationPermission::AssignAgents->blockedWhileImpersonating())->toBeTrue();
});

it('lets only owners and administrators assign agents, by policy and on the server', function () {
    $roles = [
        'org-owner' => [$this->owner, true],
        'org-admin' => [joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin), true],
        'agent' => [$this->agent, false],
        'property-owner' => [joinAs(User::factory()->create(), $this->acme, OrganizationRole::PropertyOwner), false],
        'tenant' => [joinAs(User::factory()->create(), $this->acme, OrganizationRole::Tenant), false],
        'other organization' => [joinAs(User::factory()->create(), Organization::factory()->create()), false],
        'stranger' => [User::factory()->create(), false],
    ];

    foreach ($roles as $name => [$user, $allowed]) {
        expect(inOrganization($this->acme, fn () => Gate::forUser($user)->allows('assignAgents', $this->palm)))->toBe($allowed, $name);
    }

    // Never on an archived building.
    $old = buildingIn($this->acme, ['name' => 'Old Yard', 'slug' => 'old-yard', 'status' => PropertyStatus::Archived]);
    expect(inOrganization($this->acme, fn () => Gate::forUser($this->owner)->allows('assignAgents', $old)))->toBeFalse();

    // The agent's own page has no controls; the owner's page replayed by the agent is refused.
    buildingsPage($this->agent, '/palm-court')->assertOk()->assertDontSee('data-test="building-agents"', false)->assertDontSee('data-test="agent-select"', false);
    assignIn($this->acme, $this->agent, $this->cedar);
    $ownerPage = buildingsSnapshot($this->owner, '/cedar-gardens');
    $this->actingAs($this->agent);
    LivewireHttp::update($this, $ownerPage, ['agentToAssign' => (string) $this->agent->id], [['assignAgent']])->assertForbidden();
    LivewireHttp::update($this, $ownerPage, [], [['unassignAgent', [$this->agent->id]]])->assertForbidden();
    expect(fn () => inOrganization($this->acme, fn () => app(AgentAssignments::class)->assign($this->agent, $this->palm, $this->agent->id)))
        ->toThrow(AuthorizationException::class);

    expect(assignmentsOf($this->acme)->all())->toBe([[$this->agent->id, $this->cedar->id]])
        ->and(AuditEntry::query()->where('event', 'like', 'property.agent_%')->exists())->toBeFalse();
});

it('assigns only members who are agents here, and each one once', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $both = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    inOrganization($this->acme, fn () => $both->assignRole(OrganizationRole::Agent->value));
    $landlord = joinAs(User::factory()->create(), $this->acme, OrganizationRole::PropertyOwner);
    $elsewhere = joinAs(User::factory()->create(), Organization::factory()->create(['slug' => 'other-realty']), OrganizationRole::Agent);
    $stranger = User::factory()->create();
    $page = buildingsSnapshot($this->owner, '/palm-court');

    foreach ([$this->owner, $admin, $both, $landlord, $elsewhere, $stranger] as $user) {
        $response = LivewireHttp::update($this, $page, ['agentToAssign' => (string) $user->id], [['assignAgent']])->assertOk();
        expect(htmlOf($response))->toMatch('/data-test="agent-error"[^>]*>\s*Choose an agent from the list\./');
    }
    foreach (['', 'abc', '99999999'] as $value) {
        expect(htmlOf(LivewireHttp::update($this, $page, ['agentToAssign' => $value], [['assignAgent']])->assertOk()))->toContain('Choose an agent from the list.');
    }

    // Twice: one row, one audit entry.
    LivewireHttp::update($this, $page, ['agentToAssign' => (string) $this->agent->id], [['assignAgent']])->assertOk();
    LivewireHttp::update($this, $page, ['agentToAssign' => (string) $this->agent->id], [['assignAgent']])->assertOk();
    expect(assignmentsOf($this->acme)->all())->toBe([[$this->agent->id, $this->palm->id]])
        ->and(AuditEntry::query()->where('event', 'property.agent_assigned')->count())->toBe(1);

    // Taking off someone who is not on the building: 404.
    LivewireHttp::update($this, $page, [], [['unassignAgent', [$admin->id]]])->assertNotFound();
});

it('refuses to assign agents to an archived building, even one archived after the page loaded', function () {
    $page = buildingsSnapshot($this->owner, '/palm-court');
    inOrganization($this->acme, fn () => $this->palm->forceFill(['status' => PropertyStatus::Archived])->save());

    LivewireHttp::update($this, $page, ['agentToAssign' => (string) $this->agent->id], [['assignAgent']])->assertForbidden();
    buildingsPage($this->owner, '/palm-court')->assertOk()->assertDontSee('data-test="agent-select"', false);

    expect(assignmentsOf($this->acme))->toBeEmpty();
});

it('shows assignments read-only while the organization is suspended', function () {
    assignIn($this->acme, $this->agent, $this->palm);
    $page = buildingsSnapshot($this->owner, '/palm-court');
    $second = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    buildingsPage($this->owner, '/palm-court')->assertOk()
        ->assertSee('data-agent="'.$this->agent->id.'"', false)
        ->assertDontSee('data-test="agent-select"', false)->assertDontSee('data-test="unassign-agent-', false);
    LivewireHttp::update($this, $page, ['agentToAssign' => (string) $second->id], [['assignAgent']])->assertForbidden();
    LivewireHttp::update($this, $page, [], [['unassignAgent', [$this->agent->id]]])->assertForbidden();
    expect(fn () => inOrganization($this->acme, fn () => app(AgentAssignments::class)->assign($this->owner, $this->palm, $second->id)))
        ->toThrow(AuthorizationException::class);

    // The narrowing still applies: suspension takes writes away, not the limit.
    buildingsPage($this->agent)->assertOk()->assertSee('Palm Court')->assertDontSee('Cedar Gardens');
    expect(assignmentsOf($this->acme)->all())->toBe([[$this->agent->id, $this->palm->id]]);
});

it('never lets impersonating staff assign agents, in write mode too, audited as blocked', function () {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write))->assertRedirect('/o/acme-homes');

    $page = asSupport()->get('/o/acme-homes/buildings/palm-court')->assertOk()->assertDontSee('data-test="agent-select"', false);
    freshRequest();
    LivewireHttp::update(test(), LivewireHttp::snapshot($page), ['agentToAssign' => (string) $this->agent->id], [['assignAgent']])->assertForbidden();

    expect(assignmentsOf($this->acme))->toBeEmpty()
        ->and(AuditEntry::query()->where('event', 'property.agent_assigned')->exists())->toBeFalse();
});

it('drops a removed member\'s assignments with the membership, audited, so a returning member starts unassigned', function () {
    assignIn($this->acme, $this->agent, $this->palm);
    assignIn($this->acme, $this->agent, $this->cedar);
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    joinAs($this->agent, $other, OrganizationRole::Agent);
    $elsewhere = buildingIn($other, ['name' => 'Other Court', 'slug' => 'other-court']);
    assignIn($other, $this->agent, $elsewhere);

    inOrganization($this->acme, function (): void {
        $membership = Membership::query()->where('user_id', $this->agent->id)->sole();
        app(Members::class)->remove($this->owner, $membership);
    });

    expect(assignmentsOf($this->acme))->toBeEmpty()
        // Their assignments in another organization stay.
        ->and(assignmentsOf($other)->all())->toBe([[$this->agent->id, $elsewhere->id]]);
    $dropped = AuditEntry::query()->where('event', 'property.agent_unassigned')->orderBy('id')->get();
    expect($dropped->pluck('subject_id')->all())->toBe([$this->palm->id, $this->cedar->id])
        ->and($dropped->pluck('causer_id')->unique()->all())->toBe([$this->owner->id])
        ->and($dropped->first()->properties['reason'])->toBe('member_removed');

    // Invited back: no assignment, so every building.
    joinAs($this->agent, $this->acme, OrganizationRole::Agent);
    expect(listedBuildings(buildingsPage($this->agent)->assertOk()))->toEqualCanonicalizing([$this->palm->id, $this->cedar->id]);
});

it('keeps the assignments when the removal rolls back, as they go in its transaction', function () {
    assignIn($this->acme, $this->agent, $this->palm);
    // Something after the Properties listener fails: the whole removal rolls back.
    Event::listen(MemberRemoved::class, fn () => throw new RuntimeException('Later step failed'));

    expect(fn () => inOrganization($this->acme, fn () => app(Members::class)->remove($this->owner, Membership::query()->where('user_id', $this->agent->id)->sole())))
        ->toThrow(RuntimeException::class, 'Later step failed');
    expect(assignmentsOf($this->acme)->all())->toBe([[$this->agent->id, $this->palm->id]])
        ->and(inOrganization($this->acme, fn () => Membership::query()->where('user_id', $this->agent->id)->exists()))->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'property.agent_unassigned')->exists())->toBeFalse();
});

it('lists a building\'s assigned members, current members only, marking those who see every building', function () {
    assignIn($this->acme, $this->agent, $this->palm);
    $promoted = joinAs(User::factory()->create(['name' => 'Bisi Promoted']), $this->acme, OrganizationRole::Agent);
    assignIn($this->acme, $promoted, $this->palm);
    inOrganization($this->acme, fn () => $promoted->assignRole(OrganizationRole::Admin->value));
    // A row left by a member who is gone (e.g. before P02-03 dropped them) is not shown.
    $gone = User::factory()->create(['name' => 'Gone Member']);
    assignIn($this->acme, $gone, $this->palm);

    $html = htmlOf(buildingsPage($this->owner, '/palm-court')->assertOk());
    expect($html)->toContain('Ada Agent')->toContain('Bisi Promoted')->not->toContain('Gone Member')
        ->toContain('Owner or administrator: sees every building');

    // The agent sees who works on the building, without the controls.
    buildingsPage($this->agent, '/palm-court')->assertOk()->assertSee('data-test="agent-list"', false)->assertDontSee('data-test="unassign-agent-', false);
});
