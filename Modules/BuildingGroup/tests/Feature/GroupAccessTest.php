<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Platform\Impersonation\ImpersonationMode;
use App\Tenancy\BlockedWhileImpersonating;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\Reaction;
use Modules\BuildingGroup\Models\GroupMember;
use Modules\BuildingGroup\Models\GroupPost;
use Modules\BuildingGroup\Policies\BuildingGroupPolicy;
use Modules\Leasing\Leases\LeaseService;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/group-world.php';

/*
 * P07-02: who sees and writes in a Building Group (BuildingGroupPolicy;
 * docs/plans/P07-subtasks.md defaults 10–24). Tenants: only while they are
 * members (an active lease in the building, P07-01); members of staff: the
 * groups of the buildings they see; nobody across organizations; nobody
 * when the plan lacks Building Group; read-only when the organization is
 * suspended, the group closed or the member muted; photos never while
 * GoProperty staff impersonate.
 */

beforeEach(function () {
    Storage::fake('media');
    groupWorld();
    $this->post = postIn($this->acme, $this->agent, AuthorKind::Staff, $this->palmGroup, 'Welcome to Palm Court\'s group.');
});

afterEach(fn () => groupsFreshRequest());

/** The group links a portal or workspace list shows. */
function listedGroups(TestResponse $page): array
{
    preg_match_all('/data-group="(\d+)"/', htmlOf($page), $matches);

    return array_map('intval', $matches[1]);
}

it('opens a group to the tenants who live in the building, and to nobody else', function () {
    expect(listedGroups(portalGroupPage($this->kemi)->assertOk()->assertSee('Palm Court')->assertSee('Adeyemi Realty Ltd')))->toBe([$this->palmGroup->id]);
    portalGroupPage($this->kemi, $this->palmGroup)->assertOk()->assertSee('Welcome to Palm Court');

    // Another agency's tenant: their own group only; Palm Court's is not there for them.
    expect(listedGroups(portalGroupPage($this->grace)))->toBe([$this->foreignGroup->id]);
    portalGroupPage($this->grace, $this->palmGroup)->assertNotFound();
    portalGroupPage($this->kemi, $this->foreignGroup)->assertNotFound();
    portalGroupPage($this->kemi, $this->oakGroup)->assertNotFound();
    // A member of staff with no tenancy has no groups in the portal.
    portalGroupPage($this->owner, $this->palmGroup)->assertNotFound();

    expect(fn () => postIn($this->other, $this->grace, AuthorKind::Tenant, $this->palmGroup, 'Hi'))->toThrow(Exception::class)
        ->and(fn () => postIn($this->acme, $this->grace, AuthorKind::Tenant, $this->palmGroup, 'Hi'))->toThrow(AuthorizationException::class);
});

it('closes the group to a tenant whose lease there has ended', function () {
    inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, $this->kemiLease, 'Moved to Abuja'));

    expect(listedGroups(portalGroupPage($this->kemi)))->toBe([]);
    portalGroupPage($this->kemi, $this->palmGroup)->assertNotFound();
    expect(fn () => postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Still here?'))
        ->toThrow(fn (AuthorizationException $e) => expect($e->status())->toBe(404))
        ->and(fn () => reactIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->post, Reaction::Heart))->toThrow(AuthorizationException::class);

    // Tunde stays.
    portalGroupPage($this->tunde, $this->palmGroup)->assertOk();
});

it('shows staff the groups of the buildings they see: an assigned agent\'s buildings only', function () {
    expect(listedGroups(workspaceGroupPage($this->owner)->assertOk()))->toBe([$this->palmGroup->id, $this->oakGroup->id])
        ->and(listedGroups(workspaceGroupPage($this->freeAgent)))->toBe([$this->palmGroup->id, $this->oakGroup->id])
        ->and(listedGroups(workspaceGroupPage($this->agent)))->toBe([$this->palmGroup->id]);

    workspaceGroupPage($this->agent, $this->palmGroup)->assertOk()->assertSee('data-test="post-form"', false);
    workspaceGroupPage($this->agent, $this->oakGroup)->assertNotFound();
    expect(fn () => postIn($this->acme, $this->agent, AuthorKind::Staff, $this->oakGroup, 'Hello Oak'))
        ->toThrow(fn (AuthorizationException $e) => expect($e->status())->toBe(404));

    // Another organization's group is not found from this workspace, nor this one's from theirs.
    workspaceGroupPage($this->owner, $this->foreignGroup)->assertNotFound();
    workspaceGroupPage($this->otherOwner, $this->palmGroup, 'other-realty')->assertNotFound();
    workspaceGroupPage($this->kemi)->assertNotFound();

    // The list exports, narrowed the same way.
    groupsFreshRequest();
    $html = htmlOf(workspaceGroupPage($this->agent));
    expect($html)->toContain('data-test="export-building_groups-csv"');
});

it('acceptance: keeps Building Group to the plans that include it: hidden, refused, and the posts kept', function () {
    // Starter has no Building Group (Growth and up).
    startTrial($this->acme, 'starter');

    workspaceGroupPage($this->owner)->assertForbidden();
    workspaceGroupPage($this->owner, $this->palmGroup)->assertForbidden();
    groupsFreshRequest();
    $this->actingAs($this->owner)->get('/o/acme-homes')->assertOk()->assertDontSee('/o/acme-homes/groups', false);

    expect(listedGroups(portalGroupPage($this->kemi)))->toBe([]);
    portalGroupPage($this->kemi, $this->palmGroup)->assertNotFound();
    expect(fn () => postIn($this->acme, $this->agent, AuthorKind::Staff, $this->palmGroup, 'Hello'))->toThrow(AuthorizationException::class)
        ->and(fn () => postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Hello'))->toThrow(AuthorizationException::class);

    // Bello Homes chose no plan yet: nothing gated there.
    portalGroupPage($this->grace, $this->foreignGroup)->assertOk();

    // On Growth the group is back, its posts with it.
    startTrial($this->acme, 'growth');
    portalGroupPage($this->kemi, $this->palmGroup)->assertOk()->assertSee('Welcome to Palm Court');
});

it('keeps posting to staff where the group is set so; tenants still comment and react', function () {
    inOrganization($this->acme, fn () => $this->palmGroup->forceFill(['settings' => ['members_post' => false]])->save());

    expect(fn () => postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Hello'))
        ->toThrow(AuthorizationException::class, 'In this group only the agency starts posts. You can comment and react.');
    commentIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->post, 'Thank you');
    reactIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->post, Reaction::ThumbsUp);
    postIn($this->acme, $this->agent, AuthorKind::Staff, $this->palmGroup, 'Agency notice');

    portalFeed($this->kemi, $this->palmGroup)->assertDontSee('data-test="post-form"', false)->assertSee('data-test="comment-form"', false);
});

it('lets a muted member read but not write', function () {
    inOrganization($this->acme, fn () => GroupMember::query()->where('tenant_profile_id', $this->kemiAtAcme->id)->update(['muted_until' => now()->addDay()]));

    portalFeed($this->kemi, $this->palmGroup)->assertSee('Welcome to Palm Court')
        ->assertDontSee('data-test="post-form"', false)->assertDontSee('data-test="comment-form"', false);
    expect(fn () => postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Hello'))->toThrow(AuthorizationException::class, 'You cannot write in this group for now.')
        ->and(fn () => commentIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->post, 'Hello'))->toThrow(AuthorizationException::class);

    // The mute ends by itself.
    $this->travel(25)->hours();
    commentIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->post, 'Back again');
});

it('keeps a closed group for staff to read, and writes nothing in it', function () {
    inOrganization($this->acme, fn () => $this->palmGroup->forceFill(['is_active' => false, 'closed_at' => now()])->save());

    workspaceGroupPage($this->owner, $this->palmGroup)->assertOk()->assertSee('This group is closed')->assertSee('Welcome to Palm Court')
        ->assertDontSee('data-test="post-form"', false);
    expect(fn () => postIn($this->acme, $this->owner, AuthorKind::Staff, $this->palmGroup, 'Hello'))->toThrow(AuthorizationException::class, 'This group is closed')
        ->and(fn () => commentIn($this->acme, $this->owner, AuthorKind::Staff, $this->post, 'Hello'))->toThrow(AuthorizationException::class);
    expect(listedGroups(workspaceGroupPage($this->owner)))->toBe([$this->oakGroup->id, $this->palmGroup->id]);

    // Tenants do not see a closed group.
    portalGroupPage($this->kemi, $this->palmGroup)->assertNotFound();
});

it('keeps a suspended organization\'s groups to read only, for staff and tenants', function () {
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    workspaceGroupPage($this->owner, $this->palmGroup)->assertOk()->assertSee('Welcome to Palm Court')->assertDontSee('data-test="post-form"', false);
    portalGroupPage($this->kemi, $this->palmGroup)->assertOk()->assertSee('Read-only for now')->assertDontSee('data-test="post-form"', false);

    expect(fn () => postIn($this->acme, $this->owner, AuthorKind::Staff, $this->palmGroup, 'Hello'))->toThrow(AuthorizationException::class)
        ->and(fn () => postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Hello'))->toThrow(AuthorizationException::class)
        ->and(fn () => reactIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->post, Reaction::Heart))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => groupPosts()->deletePost($this->agent, $this->post)))->toThrow(AuthorizationException::class);
});

it('lets impersonating staff in write mode post words, never photos, and hides the photos from them', function () {
    seedStaffRoles();
    postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Our new garden', [groupPhoto('garden.jpg')]);
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write))->assertRedirect('/o/acme-homes');

    $page = asSupport()->get("/o/acme-homes/groups/{$this->palmGroup->id}")->assertOk()
        ->assertSee('Our new garden')->assertSee('One photo, not shown during a support session.')
        ->assertDontSee('data-test="post-image"', false)->assertDontSee('/media/', false)
        ->assertSee('data-test="post-form"', false)->assertDontSee('data-test="post-images-input"', false);

    freshRequest();
    LivewireHttp::update(test(), LivewireHttp::snapshot($page), ['body' => '<p>Support: the gate code changed.</p>'], [['publish']])->assertOk();
    expect(inOrganization($this->acme, fn (): ?string => GroupPost::query()->latest('id')->value('body')))->toBe('<p>Support: the gate code changed.</p>');

    // Adding photos and seeing them are the policy's abilities that ImpersonationGuard refuses.
    foreach (['attachImages', 'attachImagesAsTenant', 'viewImages', 'viewImagesAsTenant'] as $ability) {
        expect((new ReflectionMethod(BuildingGroupPolicy::class, $ability))->getAttributes(BlockedWhileImpersonating::class))->not->toBeEmpty();
    }
});
