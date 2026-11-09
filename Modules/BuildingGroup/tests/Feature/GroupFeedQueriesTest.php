<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\Reaction;
use Modules\BuildingGroup\Feed\GroupFeed;
use Tests\Support\LivewireHttp;
use Tests\Support\QueryCounter;

require_once __DIR__.'/group-world.php';

/*
 * P09-03 (load test): the group feed is a hot path (tenants keep it open,
 * polling every 15 s), so a page of three posts runs the same queries as a
 * full page of posts with comments, replies and reactions, in the portal
 * and on the workspace; and a poll with nothing new runs a fixed handful,
 * however busy the group.
 */

beforeEach(function () {
    Storage::fake('media');
    groupWorld();
});

afterEach(fn () => groupsFreshRequest());

/** $posts posts by Kemi, Tunde and the owner, each with comments, a reply and reactions from all three. */
function busyGroup(int $posts): void
{
    $test = test();
    $people = [[$test->kemi, AuthorKind::Tenant], [$test->tunde, AuthorKind::Tenant], [$test->owner, AuthorKind::Staff]];

    foreach (range(1, $posts) as $p) {
        [$by, $as] = $people[$p % 3];
        $post = postIn($test->acme, $by, $as, $test->palmGroup, "Post {$p} about the water tank.");

        foreach ($people as $i => [$who, $kind]) {
            $comment = commentIn($test->acme, $who, $kind, $post, "Comment {$i} on post {$p}.");
            commentIn($test->acme, $people[($i + 1) % 3][0], $people[($i + 1) % 3][1], $post, "Reply to comment {$i}.", $comment->id);
            reactIn($test->acme, $who, $kind, $post, Reaction::cases()[$i % count(Reaction::cases())]);
            reactIn($test->acme, $who, $kind, $comment, Reaction::Heart);
        }

        // The writers' flood limits are per hour; the test writes a day's posts at once.
        test()->travel(7)->minutes();
    }
}

it('shows three posts and a full page of busy posts with the same queries, and polls in a fixed few', function () {
    $count = function (User $user, string $side): array {
        $open = fn () => $side === 'portal' ? portalGroupPage($user, test()->palmGroup) : workspaceGroupPage($user, test()->palmGroup);
        $page = QueryCounter::count(fn () => $open()->assertOk());
        $snapshot = LivewireHttp::snapshot($open()->assertOk());
        groupsFreshRequest();
        test()->actingAs($user);
        $poll = QueryCounter::count(fn () => LivewireHttp::update(test(), $snapshot, [], [['refreshFeed']])->assertOk());

        return [$page, $poll];
    };

    // Three posts: one each by Kemi, Tunde and the owner, so every kind of
    // action (removing one's own post, reporting another's) is on the page.
    busyGroup(3);
    // A first visit records things once: not the rows' doing.
    portalGroupPage($this->kemi, $this->palmGroup)->assertOk();
    workspaceGroupPage($this->owner, $this->palmGroup)->assertOk();
    $few = [$count($this->kemi, 'portal'), $count($this->owner, 'workspace')];

    // A full page (no "Show more" yet: that adds one fixed check of its own).
    busyGroup(GroupFeed::PAGE - 3);
    $many = [$count($this->kemi, 'portal'), $count($this->owner, 'workspace')];

    expect($many)->toBe($few)
        // A poll with nothing new: the session, the group check and its version only.
        ->and($many[0][1])->toBeLessThanOrEqual(20);
});
