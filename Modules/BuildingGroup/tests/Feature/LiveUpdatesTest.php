<?php

declare(strict_types=1);

use App\Hosting\Realtime;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\Reaction;
use Modules\BuildingGroup\Live\FeedChanged;
use Modules\BuildingGroup\Live\LiveFeed;
use Modules\BuildingGroup\Models\GroupPost;
use Modules\Leasing\Leases\LeaseService;
use Modules\Properties\Models\AgentAssignment;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/group-world.php';
require_once __DIR__.'/../../../../tests/Support/realtime.php';

/*
 * P07-03: open Building Group pages stay current (Live\LiveFeed,
 * RunsGroupFeed::refreshFeed; ARCHITECTURE.md § 6.8 "How live updates are
 * built"). Shared hosting: the page asks every 15 s and sees what another
 * session wrote. VPS: every write also nudges the group's private channel
 * through Reverb (the 1-second delivery itself is proven against a real
 * Reverb in tests/Drill/BuildingGroupRealtimeDrillTest.php), and only the
 * people who may open the group may listen. Sessions here are full
 * requests through the middleware (Tests\Support\LivewireHttp).
 */

beforeEach(function () {
    Storage::fake('media');
    groupWorld();
});

afterEach(fn () => groupsFreshRequest());

/** Opens the group's page as $user (portal for tenants, workspace for staff): its snapshot. */
function openFeed(mixed $user, string $side = 'portal'): string
{
    $group = test()->palmGroup;
    $page = $side === 'portal' ? portalGroupPage($user, $group) : workspaceGroupPage($user, $group);
    $page->assertOk();

    return LivewireHttp::snapshot($page);
}

/** One Livewire request from $user's session on the page whose snapshot is $snapshot. */
function onFeed(mixed $user, string $snapshot, array $updates = [], array $calls = [['refreshFeed']]): TestResponse
{
    groupsFreshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, $updates, $calls)->assertOk();
}

/** The reaction buttons a rendered feed shows: [type:id:reaction => count]. */
function shownReactions(string $html): array
{
    preg_match_all('/data-test="reactions" data-type="([^"]+)" data-id="(\d+)">(.*?)<\/div>/s', $html, $groups, PREG_SET_ORDER);
    $shown = [];

    foreach ($groups as [, $type, $id, $buttons]) {
        preg_match_all('/data-reaction="([^"]+)" data-count="(\d+)"/', $buttons, $counts, PREG_SET_ORDER);

        foreach ($counts as [, $reaction, $count]) {
            $shown["{$type}:{$id}:{$reaction}"] = (int) $count;
        }
    }

    return $shown;
}

it('acceptance (shared hosting): another session sees a post, a comment and a reaction at its next poll, within 30 s', function () {
    // The shared profile polls every 15 s, well inside the 30 s of the acceptance.
    expect(app(Realtime::class)->usesWebsockets())->toBeFalse()
        ->and(app(Realtime::class)->pollSeconds())->toBe(15)->toBeLessThanOrEqual(30);
    expect(htmlOf(portalGroupPage($this->tunde, $this->palmGroup)))->toContain('data-live-poll="15"')->not->toContain('data-live-channel');
    expect(htmlOf(workspaceGroupPage($this->owner, $this->palmGroup)))->toContain('data-live-poll="15"');

    // Two browser sessions on the group: Tunde in the portal, Ada (the agency's owner) in the workspace; Kemi writes from hers.
    $tunde = openFeed($this->tunde);
    $ada = openFeed($this->owner, 'workspace');
    $kemi = openFeed($this->kemi);

    $kemi = LivewireHttp::next(onFeed($this->kemi, $kemi, ['body' => '<p>The lift is fixed on <b>Block A</b>.</p>'], [['publish']]));
    $post = inOrganization($this->acme, fn (): GroupPost => GroupPost::query()->latest('id')->firstOrFail());

    $this->travel(15)->seconds();
    $seen = onFeed($this->tunde, $tunde);
    expect(LivewireHttp::html($seen))->toContain('The lift is fixed on <b>Block A</b>.', 'Kemi A.');
    $tunde = LivewireHttp::next($seen);
    $seen = onFeed($this->owner, $ada);
    expect(LivewireHttp::html($seen))->toContain('The lift is fixed on <b>Block A</b>.');
    $ada = LivewireHttp::next($seen);

    // A comment.
    $kemi = LivewireHttp::next(onFeed($this->kemi, $kemi, ["comments.{$post->id}" => '<p>Thank you, Ada.</p>'], [['comment', [$post->id]]]));
    $this->travel(15)->seconds();
    $seen = onFeed($this->tunde, $tunde);
    expect(LivewireHttp::html($seen))->toContain('Thank you, Ada.');
    $tunde = LivewireHttp::next($seen);

    // A reaction, then a staff reply.
    $kemi = LivewireHttp::next(onFeed($this->kemi, $kemi, [], [['react', ['group_post', $post->id, Reaction::Heart->value]]]));
    $this->travel(15)->seconds();
    $seen = onFeed($this->tunde, $tunde);
    expect(shownReactions(LivewireHttp::html($seen)))->toBe(["group_post:{$post->id}:heart" => 1]);
    $tunde = LivewireHttp::next($seen);

    $seen = onFeed($this->owner, $ada);
    expect(shownReactions(LivewireHttp::html($seen)))->toBe(["group_post:{$post->id}:heart" => 1]);
    $comment = inOrganization($this->acme, fn () => $post->comments()->firstOrFail());
    $ada = LivewireHttp::next(onFeed($this->owner, LivewireHttp::next($seen), ["replies.{$comment->id}" => '<p>You are welcome.</p>'], [['startReply', [$comment->id]], ['reply', [$post->id, $comment->id]]]));
    $this->travel(15)->seconds();
    $seen = onFeed($this->tunde, $tunde);
    expect(LivewireHttp::html($seen))->toContain('You are welcome.');
    $tunde = LivewireHttp::next($seen);

    // Kemi removes her post: gone from Tunde's page at his next poll.
    onFeed($this->kemi, $kemi, [], [['deletePost', [$post->id]]]);
    $this->travel(15)->seconds();
    expect(LivewireHttp::html(onFeed($this->tunde, $tunde)))->not->toContain('The lift is fixed')->toContain('data-test="feed-empty"');
});

it('renders nothing at a poll when the group has not changed since the page showed it', function () {
    $tunde = openFeed($this->tunde);

    $quiet = onFeed($this->tunde, $tunde);
    expect($quiet->json('components.0.effects.html'))->toBeNull();

    postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Bins go out on Thursday.');
    $changed = onFeed($this->tunde, LivewireHttp::next($quiet));
    expect(LivewireHttp::html($changed))->toContain('Bins go out on Thursday.');

    // Shown now: the next poll is quiet again.
    expect(onFeed($this->tunde, LivewireHttp::next($changed))->json('components.0.effects.html'))->toBeNull();

    // A cleared cache only costs one more render.
    cache()->flush();
    expect(LivewireHttp::html(onFeed($this->tunde, LivewireHttp::next($changed))))->toContain('Bins go out on Thursday.');
});

it('checks every poll like any request: a tenant whose lease ended is taken back to the list, and sees nothing more', function () {
    $kemi = openFeed($this->kemi);
    inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, $this->kemiLease, 'Moved to Abuja'));
    postIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->palmGroup, 'Meeting on Saturday.');

    $poll = onFeed($this->kemi, $kemi);

    expect(LivewireHttp::redirect($poll))->toEndWith('/portal/groups')
        ->and($poll->json('components.0.effects.html'))->toBeNull()
        ->and((string) $poll->getContent())->not->toContain('Meeting on Saturday');
});

it('takes an agent whose building was taken away back to the list at the next poll', function () {
    $bola = openFeed($this->agent, 'workspace');
    inOrganization($this->acme, fn () => AgentAssignment::query()->where('user_id', $this->agent->id)->update(['property_id' => $this->oak->id]));
    postIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->palmGroup, 'Meeting on Saturday.');

    $poll = onFeed($this->agent, $bola);

    expect(LivewireHttp::redirect($poll))->toEndWith('/o/acme-homes/groups')
        ->and((string) $poll->getContent())->not->toContain('Meeting on Saturday');
});

it('moves the feed version with every write, once it has committed, and broadcasts nothing on shared hosting', function () {
    Event::fake([FeedChanged::class]);
    $versions = [app(LiveFeed::class)->version($this->palmGroup->id)];
    $moved = function () use (&$versions): void {
        $versions[] = app(LiveFeed::class)->version($this->palmGroup->id);
        expect($versions[count($versions) - 1])->not->toBe($versions[count($versions) - 2]);
    };

    $post = postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Water off at noon.');
    $moved();
    $comment = commentIn($this->acme, $this->tunde, AuthorKind::Tenant, $post, 'Thanks for the warning.');
    $moved();
    reactIn($this->acme, $this->tunde, AuthorKind::Tenant, $post, Reaction::ThumbsUp);
    $moved();
    reactIn($this->acme, $this->tunde, AuthorKind::Tenant, $post, Reaction::ThumbsUp);
    $moved();
    inOrganization($this->acme, fn () => groupPosts()->deleteComment($this->tunde, $comment));
    $moved();

    // A write that rolls back moves nothing.
    $before = app(LiveFeed::class)->version($this->palmGroup->id);
    DB::beginTransaction();
    postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Never mind.');
    DB::rollBack();
    expect(app(LiveFeed::class)->version($this->palmGroup->id))->toBe($before)
        ->and(app(LiveFeed::class)->version($this->oakGroup->id))->toBe('');

    Event::assertNotDispatched(FeedChanged::class);
});

it('VPS: the page listens on its group\'s private channel, polling every 60 s as the fallback, and is never told the secret', function () {
    useReverb();

    foreach ([portalGroupPage($this->tunde, $this->palmGroup), workspaceGroupPage($this->owner, $this->palmGroup)] as $page) {
        $html = htmlOf($page);

        expect($html)->toContain('data-live-poll="60"', "data-live-channel=\"group.{$this->palmGroup->id}\"", 'gp-live-key', 'ws.goproperty.test', 'broadcasting')
            ->not->toContain(REVERB_TEST_APP['secret']);
    }
});

it('VPS: every write nudges the group\'s channel once, after the commit, with no words', function () {
    useReverb();
    Event::fake([FeedChanged::class]);

    $post = postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Generator service on Monday.');
    $comment = commentIn($this->acme, $this->owner, AuthorKind::Staff, $post, 'From 9 to 11.');
    commentIn($this->acme, $this->tunde, AuthorKind::Tenant, $post, 'Noted.', $comment->id);
    reactIn($this->acme, $this->tunde, AuthorKind::Tenant, $comment, Reaction::Thanks);
    reactIn($this->acme, $this->tunde, AuthorKind::Tenant, $comment, Reaction::Thanks);
    inOrganization($this->acme, fn () => groupPosts()->deleteComment($this->owner, $comment));
    inOrganization($this->acme, fn () => groupPosts()->deletePost($this->kemi, $post));

    // Refused and rolled-back writes say nothing.
    expect(fn () => postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, '   '))->toThrow(ValidationException::class);
    DB::beginTransaction();
    postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Never mind.');
    DB::rollBack();

    Event::assertDispatchedTimes(FeedChanged::class, 7);
    Event::assertDispatched(FeedChanged::class, function (FeedChanged $event): bool {
        expect($event->broadcastOn()[0]->name)->toBe("private-group.{$this->palmGroup->id}")
            ->and($event->broadcastAs())->toBe('feed.changed')
            ->and(array_keys($event->broadcastWith()))->toBe(['version'])
            ->and(json_encode($event->broadcastWith()))->not->toContain('Generator', 'From 9', 'Noted', 'Kemi');

        return true;
    });
});

it('VPS: a write stands when Reverb cannot be reached, and other pages catch up at their next poll', function () {
    useReverb(port: freeTcpPort());
    Exceptions::fake();
    $tunde = openFeed($this->tunde);
    $kemi = openFeed($this->kemi);

    onFeed($this->kemi, $kemi, ['body' => '<p>Gate code is 4471.</p>'], [['publish']]);

    expect(inOrganization($this->acme, fn (): int => GroupPost::query()->count()))->toBe(1)
        ->and(LivewireHttp::html(onFeed($this->tunde, $tunde)))->toContain('Gate code is 4471.');
    Exceptions::assertReported(BroadcastException::class);
});

/** Asks /broadcasting/auth for the private channel of $group as $user (null: a guest). */
function listenTo(mixed $user, int|string $group): TestResponse
{
    groupsFreshRequest();
    auth()->logout();

    if ($user !== null) {
        test()->actingAs($user);
    }

    return test()->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-group.{$group}"]);
}

describe('who may listen (VPS)', function () {
    beforeEach(fn () => useReverb());

    it('acceptance: lets exactly the people who may open the group listen to it', function () {
        $palm = $this->palmGroup->id;

        foreach (['Kemi' => $this->kemi, 'Tunde' => $this->tunde, 'Ada the owner' => $this->owner, 'Seyi the administrator' => $this->admin, 'Bola, Palm Court\'s agent' => $this->agent] as $who => $user) {
            $answer = listenTo($user, $palm);
            expect($answer->status())->toBe(200, $who)
                ->and($answer->json('auth'))->toBe(REVERB_TEST_APP['key'].':'.hash_hmac('sha256', "1234.5678:private-group.{$palm}", REVERB_TEST_APP['secret']));
        }

        foreach ([
            'a tenant of another building' => [$this->kemi, $this->oakGroup->id],
            'a tenant of another agency' => [$this->grace, $palm],
            'an agent outside their buildings' => [$this->agent, $this->oakGroup->id],
            'another agency\'s owner' => [$this->otherOwner, $palm],
            'a tenant at the other agency\'s group' => [$this->kemi, $this->foreignGroup->id],
            'no such group' => [$this->owner, 999999],
            'not a group id' => [$this->owner, 'abc'],
            'a leading zero' => [$this->owner, '0'.$palm],
        ] as $who => [$user, $group]) {
            expect(listenTo($user, $group)->status())->toBe(403, $who);
        }

        expect(listenTo(null, $palm)->status())->not->toBe(200);
    });

    it('stops a tenant listening once their lease there ends', function () {
        expect(listenTo($this->kemi, $this->palmGroup->id)->status())->toBe(200);

        inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, $this->kemiLease, 'Moved to Abuja'));

        expect(listenTo($this->kemi, $this->palmGroup->id)->status())->toBe(403)
            ->and(listenTo($this->tunde, $this->palmGroup->id)->status())->toBe(200);
    });

    it('lets nobody listen when the agency\'s plan has no Building Group', function () {
        startTrial($this->acme, 'starter');

        expect(listenTo($this->kemi, $this->palmGroup->id)->status())->toBe(403)
            ->and(listenTo($this->owner, $this->palmGroup->id)->status())->toBe(403);
    });
});
