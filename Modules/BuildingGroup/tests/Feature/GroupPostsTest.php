<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Media\Media;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\Reaction;
use Modules\BuildingGroup\Feed\AuthorNames;
use Modules\BuildingGroup\Models\GroupComment;
use Modules\BuildingGroup\Models\GroupPost;
use Modules\BuildingGroup\Models\GroupReaction;
use Modules\BuildingGroup\Posts\GroupPosts;

require_once __DIR__.'/group-world.php';

/*
 * P07-02: posts, comments and reactions (ARCHITECTURE.md § 6.8;
 * docs/plans/P07-subtasks.md defaults 10–24). Tenants post from the portal
 * as their tenant record, staff from the workspace with the agency's name
 * beside theirs; comments have one level of replies; one reaction per
 * person and item from the curated set; authors remove their own; limits
 * on length and on how often.
 */

beforeEach(function () {
    Storage::fake('media');
    groupWorld();
});

afterEach(fn () => groupsFreshRequest());

/** The post ids a page shows, in order. */
function shownPosts(TestResponse|string $page): array
{
    preg_match_all('/data-test="post" data-post="(\d+)"/', htmlOf($page), $matches);

    return array_map('intval', $matches[1]);
}

it('lets a tenant post from the portal and staff from the workspace, each shown as who they are, to everyone in the group', function () {
    $kemiPost = postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, '<p>Lost a black cat near the gate.</p>');
    $this->travel(5)->minutes();
    $agencyPost = postIn($this->acme, $this->agent, AuthorKind::Staff, $this->palmGroup, '<p>Generator servicing on <strong>Friday</strong>.</p>');

    expect($kemiPost->author_kind)->toBe(AuthorKind::Tenant)
        ->and($kemiPost->author_user_id)->toBe($this->kemi->id)
        ->and($kemiPost->author_tenant_profile_id)->toBe($this->kemiAtAcme->id)
        ->and($agencyPost->author_kind)->toBe(AuthorKind::Staff)
        ->and($agencyPost->author_tenant_profile_id)->toBeNull();

    // Tunde (a neighbour) sees both, newest activity first: first name and initial for tenants, the agency beside staff.
    $page = portalGroupPage($this->tunde, $this->palmGroup)->assertOk()
        ->assertSee('Kemi A.')->assertDontSee('Kemi Adebayo')->assertDontSee('+2348031112222')->assertDontSee('Flat 3B')
        ->assertSee('Bola Agent')->assertSee('Adeyemi Realty Ltd')
        ->assertSee('Lost a black cat near the gate.')->assertSee('15 Mar 2027, 11:00');
    expect(shownPosts($page))->toBe([$agencyPost->id, $kemiPost->id]);

    // The workspace's page shows the same feed to the agency.
    expect(shownPosts(workspaceGroupPage($this->owner, $this->palmGroup)->assertOk()->assertSee('Kemi A.')))->toBe([$agencyPost->id, $kemiPost->id]);

    $audit = inOrganization($this->acme, fn () => AuditEntry::query()->where('event', 'group.post_published')->orderBy('id')->get());
    expect($audit)->toHaveCount(2)
        ->and($audit[0]->properties->get('author'))->toBe('tenant')
        ->and($audit[0]->properties->get('tenant_profile_id'))->toBe($this->kemiAtAcme->id)
        // Never the words.
        ->and(json_encode($audit[0]->properties))->not->toContain('black cat');
});

it('posts and comments from the pages themselves', function () {
    portalFeed($this->kemi, $this->palmGroup)->assertSee('No posts yet')
        ->set('body', '<p>Who has a ladder?</p>')->call('publish')
        ->assertHasNoErrors()->assertSee('Posted.')->assertSet('body', '')->assertSee('Who has a ladder?');
    $post = inOrganization($this->acme, fn (): GroupPost => GroupPost::query()->sole());

    // Every shown post's comment box has a value for the editor to bind to (it cannot bind to a missing key).
    $workspace = workspaceFeed($this->agent, $this->acme, $this->palmGroup)->assertSet("comments.{$post->id}", '');
    $workspace->set("comments.{$post->id}", '<p>The caretaker has one.</p>')->call('comment', $post->id)
        ->assertHasNoErrors()->assertSee('The caretaker has one.')->assertSee('Adeyemi Realty Ltd')
        ->assertSet("comments.{$post->id}", '');
    $comment = inOrganization($this->acme, fn (): GroupComment => GroupComment::query()->sole());
    $workspace->call('startReply', $comment->id)->assertSet("replies.{$comment->id}", '')->assertSee('data-test="reply-form"', false);

    portalFeed($this->kemi, $this->palmGroup)->assertSee('The caretaker has one.');
    expect(storedPost($this->acme, $post)->comment_count)->toBe(1);
});

it('files replies one level deep, and brings a commented post to the top', function () {
    $older = postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Water pressure is low on the 3rd floor.');
    $this->travel(1)->hour();
    $newer = postIn($this->acme, $this->agent, AuthorKind::Staff, $this->palmGroup, 'Welcome to the group.');
    expect(shownPosts(portalGroupPage($this->tunde, $this->palmGroup)))->toBe([$newer->id, $older->id]);

    $this->travel(1)->hour();
    $comment = commentIn($this->acme, $this->tunde, AuthorKind::Tenant, $older, 'Same on the 4th.');
    $reply = commentIn($this->acme, $this->agent, AuthorKind::Staff, $older, 'A plumber is coming.', $comment->id);
    // A reply to a reply goes under the same comment: one level only.
    $replyToReply = commentIn($this->acme, $this->kemi, AuthorKind::Tenant, $older, 'Thank you!', $reply->id);

    expect($reply->parent_id)->toBe($comment->id)
        ->and($replyToReply->parent_id)->toBe($comment->id)
        ->and($comment->author_tenant_profile_id)->toBe($this->tundeAtAcme->id)
        ->and(storedPost($this->acme, $older)->comment_count)->toBe(3)
        ->and(storedPost($this->acme, $older)->last_activity_at->equalTo(now()))->toBeTrue();

    $page = portalGroupPage($this->kemi, $this->palmGroup);
    expect(shownPosts($page))->toBe([$older->id, $newer->id]);
    preg_match_all('/data-test="(comment|reply)" data-comment="(\d+)"/', htmlOf($page), $matches);
    expect(array_map(null, $matches[1], array_map('intval', $matches[2])))->toBe([['comment', $comment->id], ['reply', $reply->id], ['reply', $replyToReply->id]]);

    // A comment of another post is not a place to reply.
    expect(fn () => commentIn($this->acme, $this->kemi, AuthorKind::Tenant, $newer, 'Wrong thread', $comment->id))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['comment' => ['That comment is no longer there.']]));
});

it('keeps one reaction per person and item: the same again takes it back, another replaces it', function () {
    $post = postIn($this->acme, $this->agent, AuthorKind::Staff, $this->palmGroup, 'Rent reminders go out on the 25th.');
    $comment = commentIn($this->acme, $this->kemi, AuthorKind::Tenant, $post, 'Noted.');

    expect(reactIn($this->acme, $this->kemi, AuthorKind::Tenant, $post, Reaction::ThumbsUp))->toBe(Reaction::ThumbsUp)
        ->and(reactIn($this->acme, $this->tunde, AuthorKind::Tenant, $post, Reaction::ThumbsUp))->toBe(Reaction::ThumbsUp)
        ->and(reactIn($this->acme, $this->kemi, AuthorKind::Tenant, $post, Reaction::Heart))->toBe(Reaction::Heart)
        ->and(reactIn($this->acme, $this->agent, AuthorKind::Staff, $comment, Reaction::Thanks))->toBe(Reaction::Thanks);

    $rows = fn (): array => inOrganization($this->acme, fn (): array => GroupReaction::query()->orderBy('id')->get()
        ->map(fn (GroupReaction $row): array => [$row->user_id, $row->reactable_type->value, $row->reactable_id, $row->emoji->value])->all());
    expect($rows())->toBe([
        [$this->kemi->id, 'group_post', $post->id, 'heart'],
        [$this->tunde->id, 'group_post', $post->id, 'thumbs_up'],
        [$this->agent->id, 'group_comment', $comment->id, 'thanks'],
    ]);

    // Kemi sees the counts and her own marked.
    $html = htmlOf(portalGroupPage($this->kemi, $this->palmGroup));
    expect($html)->toMatch('/data-reaction="thumbs_up" data-count="1"/')
        ->toMatch('/aria-pressed="true"[^>]*data-reaction="heart" data-count="1"/');

    // The same again takes it back.
    expect(reactIn($this->acme, $this->kemi, AuthorKind::Tenant, $post, Reaction::Heart))->toBeNull()
        ->and($rows())->toHaveCount(2);

    // From the page, and only from the curated set.
    $page = portalFeed($this->tunde, $this->palmGroup)->call('react', 'group_post', $post->id, 'laugh')->assertHasNoErrors();
    expect($rows()[0])->toBe([$this->tunde->id, 'group_post', $post->id, 'laugh']);
    $page->call('react', 'group_post', $post->id, 'skull')->assertHasErrors(['group' => 'Choose one of the reactions shown.']);
});

it('lets authors remove their own post, its comments going with it and its photos deleted for good, and nobody else\'s', function () {
    $post = postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Selling a fridge', [groupPhoto('fridge.jpg')]);
    commentIn($this->acme, $this->tunde, AuthorKind::Tenant, $post, 'How much?');
    $image = inOrganization($this->acme, fn (): Media => Media::query()->where('collection_name', GroupPost::IMAGES)->sole());
    $checksum = $image->checksum();
    expect(Storage::disk('media')->allFiles())->not->toBeEmpty();

    // Tunde may not remove Kemi's post: it is not his (404), and the page offers nothing.
    portalFeed($this->tunde, $this->palmGroup)->assertDontSee('data-test="delete-post"', false)
        ->call('deletePost', $post->id)->assertStatus(404);
    expect(fn () => inOrganization($this->acme, fn () => groupPosts()->deletePost($this->owner, $post)))
        ->toThrow(fn (AuthorizationException $e) => expect($e->status())->toBe(404));

    portalFeed($this->kemi, $this->palmGroup)->assertSee('data-test="delete-post"', false)
        ->call('deletePost', $post->id)->assertHasNoErrors()->assertSee('Your post was removed.')->assertDontSee('Selling a fridge')->assertDontSee('How much?');

    expect(storedPost($this->acme, $post)->deleted_at)->not->toBeNull()
        ->and(inOrganization($this->acme, fn (): int => Media::query()->count()))->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);

    $audit = inOrganization($this->acme, fn () => AuditEntry::query()->where('event', 'group.post_deleted')->sole());
    expect($audit->causer_id)->toBe($this->kemi->id)
        ->and($audit->properties->get('images_deleted'))->toBe([['media_uuid' => $image->uuid, 'file_sha256' => $checksum]]);

    // Gone for everyone; nothing can be said under it any more.
    expect(shownPosts(workspaceGroupPage($this->owner, $this->palmGroup)))->toBe([])
        ->and(fn () => commentIn($this->acme, $this->tunde, AuthorKind::Tenant, $post, 'Still for sale?'))->toThrow(ValidationException::class, 'That post is no longer there.')
        ->and(fn () => $post->forceDelete())->toThrow(LogicException::class);
});

it('lets authors remove their own comment, the replies under it going too', function () {
    $post = postIn($this->acme, $this->agent, AuthorKind::Staff, $this->palmGroup, 'Fumigation next week.');
    $comment = commentIn($this->acme, $this->kemi, AuthorKind::Tenant, $post, 'Which day?');
    commentIn($this->acme, $this->agent, AuthorKind::Staff, $post, 'Tuesday.', $comment->id);
    $other = commentIn($this->acme, $this->tunde, AuthorKind::Tenant, $post, 'Thanks');
    expect(storedPost($this->acme, $post)->comment_count)->toBe(3);

    portalFeed($this->tunde, $this->palmGroup)->call('deleteComment', $comment->id)->assertStatus(404);
    portalFeed($this->kemi, $this->palmGroup)->call('deleteComment', $comment->id)->assertHasNoErrors()
        ->assertDontSee('Which day?')->assertDontSee('Tuesday.')->assertSee('Thanks');

    expect(storedPost($this->acme, $post)->comment_count)->toBe(1)
        ->and(inOrganization($this->acme, fn (): array => GroupComment::query()->pluck('id')->all()))->toBe([$other->id])
        ->and(inOrganization($this->acme, fn (): int => GroupComment::query()->onlyTrashed()->count()))->toBe(2);
});

it('limits a post to 5,000 characters and a comment to 2,000, counting the words, not the markup', function () {
    $words = str_repeat('a', GroupPosts::POST_MAX);
    $post = postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, '<p><strong>'.$words.'</strong></p>');
    expect(mb_strlen(strip_tags((string) $post->body)))->toBe(5000);

    expect(fn () => postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, $words.'b'))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['body' => ['Keep it to 5,000 characters (this has 5,001).']]));
    expect(fn () => commentIn($this->acme, $this->kemi, AuthorKind::Tenant, $post, str_repeat('c', 2001)))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['comment' => ['Keep it to 2,000 characters (this has 2,001).']]));
    expect(fn () => commentIn($this->acme, $this->kemi, AuthorKind::Tenant, $post, '<p> </p>'))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['comment' => ['Write your comment first.']]));
});

it('limits how often one person posts, comments and reacts', function () {
    for ($i = 1; $i <= GroupPosts::POSTS_PER_HOUR; $i++) {
        postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, "Post {$i}");
    }

    expect(fn () => postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'One more'))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['body' => ['You have posted a lot this hour. Try again in 60 minutes.']]));
    // Others are not held back by Kemi's posts.
    postIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->palmGroup, 'Hello');

    $this->travel(61)->minutes();
    postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'One more');

    RateLimiter::clear('group-reactions:'.$this->kemi->id);
    $post = inOrganization($this->acme, fn (): GroupPost => GroupPost::query()->latest('id')->firstOrFail());
    for ($i = 0; $i < GroupPosts::REACTIONS_PER_MINUTE; $i++) {
        reactIn($this->acme, $this->kemi, AuthorKind::Tenant, $post, Reaction::ThumbsUp);
    }
    expect(fn () => reactIn($this->acme, $this->kemi, AuthorKind::Tenant, $post, Reaction::ThumbsUp))
        ->toThrow(ValidationException::class, 'Too many reactions. Try again in a minute.');
});

it('shortens tenants\' names to a first name and an initial', function (string $name, string $shown) {
    expect(AuthorNames::short($name))->toBe($shown);
})->with([
    ['Kemi Adebayo', 'Kemi A.'],
    ['Oluwaseun Tolu Bakare', 'Oluwaseun B.'],
    ['Ngozi', 'Ngozi'],
    ['  ', 'A neighbour'],
    ['émile ọkọ', 'émile Ọ.'],
]);

it('shows twenty posts at a time', function () {
    for ($i = 1; $i <= 22; $i++) {
        RateLimiter::clear('group-posts:'.$this->agent->id);
        postIn($this->acme, $this->agent, AuthorKind::Staff, $this->palmGroup, "Notice {$i}");
        $this->travel(1)->minute();
    }

    $page = portalFeed($this->kemi, $this->palmGroup)->assertSee('Notice 22')->assertDontSee('Notice 2<')->assertSee('Show more');
    expect(shownPosts($page->html()))->toHaveCount(20);
    $page->call('showMore')->assertDontSee('data-test="show-more"', false);
    expect(shownPosts($page->html()))->toHaveCount(22);
});
