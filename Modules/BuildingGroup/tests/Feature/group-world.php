<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\ReactableType;
use Modules\BuildingGroup\Enums\Reaction;
use Modules\BuildingGroup\Livewire\GroupShow;
use Modules\BuildingGroup\Livewire\Portal\PortalGroupShow;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupComment;
use Modules\BuildingGroup\Models\GroupPost;
use Modules\BuildingGroup\Posts\GroupPosts;

/*
 * The Building Group's posts world (P07-02). Fixed names, phones and
 * emails:
 *
 * Adeyemi Realty (acme-homes, no plan chosen yet: nothing gated): Ada the
 * owner, Seyi an administrator, Bola an agent assigned to Palm Court only,
 * Chika an agent with no assignment. Palm Court: Kemi Adebayo (Flat 3B)
 * and Tunde Bakare (Flat 4C), both with portal accounts and active leases,
 * so both members of Palm Court's group. Oak Lodge: Femi Okon (Unit 1, no
 * portal account). Bello Homes (other-realty): Grace Eze rents Suite 9 at
 * Foreign Court with a portal account.
 */

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../tests/Support/media.php';
require_once __DIR__.'/../../../../tests/Support/plans.php';

function groupWorld(): void
{
    $test = test();
    (function () {
        seedOrganizationRoles();
        $this->travelTo('2027-03-15 10:00:00');

        $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
        $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes Ltd']);
        $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com']), $this->acme);
        $this->admin = joinAs(User::factory()->create(['name' => 'Seyi Admin', 'email' => 'seyi.admin@example.com']), $this->acme, OrganizationRole::Admin);
        $this->agent = joinAs(User::factory()->create(['name' => 'Bola Agent', 'email' => 'bola.agent@example.com']), $this->acme, OrganizationRole::Agent);
        $this->freeAgent = joinAs(User::factory()->create(['name' => 'Chika Agent', 'email' => 'chika.agent@example.com']), $this->acme, OrganizationRole::Agent);
        $this->otherOwner = joinAs(User::factory()->create(['name' => 'Chidi Owner', 'email' => 'chidi.owner@example.com']), $this->other);

        $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
        $this->oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
        assignIn($this->acme, $this->agent, $this->palm);

        $this->flat3b = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 3B']);
        $this->flat4c = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 4C']);
        $this->oakUnit = unitIn($this->acme, $this->oak, ['unit_label' => 'Unit 1']);

        $this->kemi = User::factory()->create(['name' => 'Kemi Adebayo', 'email' => 'kemi.adebayo@example.com', 'phone' => '+2348031112222']);
        $this->kemiAtAcme = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com', 'user_id' => $this->kemi->id]);
        $this->kemiLease = activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);

        $this->tunde = User::factory()->create(['name' => 'Tunde Bakare', 'email' => 'tunde.bakare@example.com', 'phone' => '+2348034445555']);
        $this->tundeAtAcme = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348034445555', 'email' => 'tunde.bakare@example.com', 'user_id' => $this->tunde->id]);
        $this->tundeLease = activeLease($this->acme, $this->owner, $this->flat4c, $this->tundeAtAcme, ['rent' => '1,800,000']);

        $this->femi = tenantIn($this->acme, ['first_name' => 'Femi', 'last_name' => 'Okon', 'phone' => '+2348036660000', 'email' => 'femi.okon@example.com']);
        activeLease($this->acme, $this->owner, $this->oakUnit, $this->femi, ['rent' => '900,000']);

        $foreignBuilding = buildingIn($this->other, ['name' => 'Foreign Court', 'slug' => 'foreign-court']);
        $foreignUnit = unitIn($this->other, $foreignBuilding, ['unit_label' => 'Suite 9']);
        $this->grace = User::factory()->create(['name' => 'Grace Eze', 'email' => 'grace.eze@example.com', 'phone' => '+2348035550000']);
        $graceAtOther = tenantIn($this->other, ['first_name' => 'Grace', 'last_name' => 'Eze', 'phone' => '+2348035550000', 'email' => 'grace.eze@example.com', 'user_id' => $this->grace->id]);
        activeLease($this->other, $this->otherOwner, $foreignUnit, $graceAtOther, ['rent' => '2,400,000']);

        $this->palmGroup = groupOf($this->acme, $this->palm);
        $this->oakGroup = groupOf($this->acme, $this->oak);
        $this->foreignGroup = groupOf($this->other, $foreignBuilding);
    })->call($test);
}

function groupPosts(): GroupPosts
{
    return app(GroupPosts::class);
}

/** A post through the writer, in the group's organization. */
function postIn(Organization $organization, User $by, AuthorKind $as, BuildingGroup $group, ?string $body, array $images = []): GroupPost
{
    groupsFreshRequest();

    return inOrganization($organization, fn (): GroupPost => groupPosts()->post($by, $as, $group, $body, $images));
}

/** A comment (or, with $replyTo, a reply) through the writer. */
function commentIn(Organization $organization, User $by, AuthorKind $as, GroupPost $post, ?string $body, ?int $replyTo = null): GroupComment
{
    groupsFreshRequest();

    return inOrganization($organization, fn (): GroupComment => groupPosts()->comment($by, $as, $post, $body, $replyTo));
}

/** A reaction through the writer: the person's reaction now. */
function reactIn(Organization $organization, User $by, AuthorKind $as, GroupPost|GroupComment $item, Reaction $emoji): ?Reaction
{
    groupsFreshRequest();
    $type = $item instanceof GroupPost ? ReactableType::Post : ReactableType::Comment;

    return inOrganization($organization, fn (): ?Reaction => groupPosts()->react($by, $as, $type, $item->id, $emoji));
}

/** The post as stored now (removed ones too). */
function storedPost(Organization $organization, GroupPost|int $post): ?GroupPost
{
    $id = $post instanceof GroupPost ? $post->id : $post;

    return inOrganization($organization, fn (): ?GroupPost => GroupPost::query()->withTrashed()->find($id));
}

function groupsFreshRequest(): void
{
    leasesFreshRequest();
}

/** The workspace page of a group (or the list with no group), as the user. */
function workspaceGroupPage(User $user, ?BuildingGroup $group = null, string $slug = 'acme-homes'): TestResponse
{
    groupsFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/groups".($group !== null ? "/{$group->id}" : ''));
}

/** The portal page of a group (or the list with no group), as the user. */
function portalGroupPage(User $user, ?BuildingGroup $group = null): TestResponse
{
    groupsFreshRequest();

    return test()->actingAs($user)->get('/portal/groups'.($group !== null ? "/{$group->id}" : ''));
}

/** The group's workspace page as a Livewire test, as the member. */
function workspaceFeed(User $user, Organization $organization, BuildingGroup $group): mixed
{
    groupsFreshRequest();
    test()->actingAs($user);
    app(OrganizationContext::class)->set($organization);

    return Livewire::test(GroupShow::class, ['group' => $group->id]);
}

/** The group's portal page as a Livewire test, as the tenant. */
function portalFeed(User $user, BuildingGroup $group): mixed
{
    groupsFreshRequest();
    test()->actingAs($user);

    return Livewire::test(PortalGroupShow::class, ['group' => $group->id]);
}

/** Chooses photos at the real time (Livewire's test uploader judges their age by the clock). */
function choosePostImages(mixed $component, array $files): mixed
{
    $now = now();
    test()->travelBack();
    $component->set('images', $files);
    test()->travelTo($now);

    return $component;
}

/**
 * The words of every post and comment a page shows (the only HTML the
 * module prints unescaped), joined.
 */
function shownRichText(TestResponse|string $page): string
{
    preg_match_all('/<div class="group-rich-text[^"]*" data-test="rich-text">(.*?)<\/div>/s', htmlOf($page), $matches);

    return implode("\n", $matches[1]);
}

/** A real JPEG, PNG or WebP photo as an upload (a testing file, so Livewire's test uploader takes it too). */
function groupPhoto(string $name = 'garden.jpg', string $type = 'jpeg', int $width = 800, int $height = 600): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents((string) mediaImage($name, $width, $height, $type)->getRealPath()));
}
