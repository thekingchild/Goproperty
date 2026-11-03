<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Livewire\Concerns;

use App\Hosting\Realtime;
use App\Hosting\WebsocketClient;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\ReactableType;
use Modules\BuildingGroup\Enums\Reaction;
use Modules\BuildingGroup\Feed\FeedPost;
use Modules\BuildingGroup\Feed\GroupFeed;
use Modules\BuildingGroup\Live\LiveFeed;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupComment;
use Modules\BuildingGroup\Models\GroupPost;
use Modules\BuildingGroup\Posts\GroupPosts;
use Modules\BuildingGroup\Posts\GroupRefused;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A group's page, on the workspace and in the portal (P07-02): the post
 * form (rich text and up to 4 photos), the feed, comments and one level
 * of replies, reactions, and removing one's own post or comment. Every
 * action goes through Posts\GroupPosts as the page's side (author()),
 * inside the group's organization (inGroup(), which loads the group and
 * checks the page's view ability on every request), and the writer checks
 * the policy again. The feed is read in render(), never kept in a public
 * property. A refused action shows its reason where it was asked for:
 * the post form (`body`, `images`), a comment box (`comments.{post}`), a
 * reply box (`replies.{comment}`) or the page (`group`).
 *
 * Live updates (P07-03, Live\LiveFeed): the page calls refreshFeed() every
 * few seconds (App\Hosting\Realtime::pollSeconds()) and, on a VPS, when
 * Reverb says the group changed (Alpine `groupLive`, resources/js/app.js).
 * It is checked like any action and renders again only when the group's
 * feed version moved since the page last showed it; a person who may no
 * longer see the group is taken to the list of groups.
 */
trait RunsGroupFeed
{
    use WithFileUploads;

    /** The new post's words (rich text, cleaned by the writer). */
    public string $body = '';

    /** @var array<int, mixed> the new post's photos (Livewire's temporary uploads) */
    public array $images = [];

    /** @var array<int|string, string> a comment being written, by post id */
    public array $comments = [];

    /** @var array<int|string, string> a reply being written, by comment id */
    public array $replies = [];

    /** The comment whose reply box is open. */
    public ?int $replyingTo = null;

    /** Posts shown; "Show more" adds a page. */
    public int $limit = GroupFeed::PAGE;

    #[Locked]
    public ?string $notice = null;

    /** The group's feed version this page last showed (Live\LiveFeed). */
    #[Locked]
    public string $seen = '';

    /** The side this page writes from. */
    abstract protected function author(): AuthorKind;

    /**
     * Runs $callback with the group (checked for this page's view ability)
     * and the user, inside the group's organization.
     *
     * @template T
     *
     * @param  Closure(BuildingGroup, User): T  $callback
     * @return T
     */
    abstract protected function inGroup(Closure $callback): mixed;

    /** Where the page goes when the person may no longer see the group. */
    abstract protected function leaveTo(): string;

    /** Brings the feed up to date: re-renders only when the group changed since it was shown. */
    public function refreshFeed(LiveFeed $live): void
    {
        try {
            $this->inGroup(static fn (BuildingGroup $group): int => $group->id);
        } catch (AuthorizationException|HttpExceptionInterface) {
            $this->redirect($this->leaveTo(), navigate: true);

            return;
        }

        if ($live->version($this->groupId) === $this->seen) {
            $this->skipRender();
        }
    }

    public function publish(GroupPosts $posts): void
    {
        try {
            $this->inGroup(fn (BuildingGroup $group, User $user): GroupPost => $posts->post($user, $this->author(), $group, $this->body, $this->chosenImages()));
        } catch (ValidationException $refused) {
            // New choices add to the chosen photos, so refused ones go: the person chooses again.
            if (array_key_exists('images', $refused->errors())) {
                $this->images = [];
            }

            throw $refused;
        }

        $this->reset('body', 'images');
        $this->resetValidation();
        $this->notice = __('Posted.');
    }

    public function comment(GroupPosts $posts, int $postId): void
    {
        $this->as("comments.{$postId}", fn () => $this->inGroup(fn (BuildingGroup $group, User $user): GroupComment => $posts->comment($user, $this->author(), $this->postIn($group, $postId), $this->comments[$postId] ?? '')));

        $this->comments[$postId] = '';
        $this->resetValidation("comments.{$postId}");
        $this->notice = null;
    }

    public function reply(GroupPosts $posts, int $postId, int $commentId): void
    {
        $this->as("replies.{$commentId}", fn () => $this->inGroup(fn (BuildingGroup $group, User $user): GroupComment => $posts->comment($user, $this->author(), $this->postIn($group, $postId), $this->replies[$commentId] ?? '', $commentId)));

        unset($this->replies[$commentId]);
        $this->replyingTo = null;
        $this->resetValidation("replies.{$commentId}");
        $this->notice = null;
    }

    /** Takes the chosen photos off the post form. */
    public function clearImages(): void
    {
        $this->images = [];
        $this->resetValidation(['images', 'images.*']);
    }

    public function startReply(int $commentId): void
    {
        $this->replyingTo = $commentId;
    }

    public function cancelReply(): void
    {
        $this->replyingTo = null;
    }

    public function react(GroupPosts $posts, string $type, int $id, string $emoji): void
    {
        $type = ReactableType::tryFrom($type);
        $emoji = Reaction::tryFrom($emoji);

        if ($type === null || $emoji === null) {
            throw GroupRefused::because(__('Choose one of the reactions shown.'));
        }

        $this->as('group', fn () => $this->inGroup(fn (BuildingGroup $group, User $user): ?Reaction => $posts->react($user, $this->author(), $type, $this->itemIn($group, $type, $id), $emoji)));
        $this->notice = null;
    }

    public function deletePost(GroupPosts $posts, int $postId): void
    {
        $this->as('group', fn () => $this->inGroup(function (BuildingGroup $group, User $user) use ($posts, $postId): void {
            $posts->deletePost($user, $this->postIn($group, $postId));
        }));

        $this->notice = __('Your post was removed.');
    }

    public function deleteComment(GroupPosts $posts, int $commentId): void
    {
        $this->as('group', fn () => $this->inGroup(function (BuildingGroup $group, User $user) use ($posts, $commentId): void {
            $posts->deleteComment($user, $this->commentIn($group, $commentId));
        }));

        $this->notice = __('Your comment was removed.');
    }

    public function showMore(): void
    {
        $this->limit = min($this->limit + GroupFeed::PAGE, 10 * GroupFeed::PAGE);
    }

    /**
     * Notes the feed version about to be shown (read before the feed, so a
     * write in between only causes one more render) and returns the page's
     * live settings for the feed partial: seconds between polls, seconds of
     * inactivity before polling pauses, and Reverb's browser settings on a
     * VPS (null on shared hosting).
     *
     * @return array{poll: int, idle: int, websocket: array<string, int|string>|null}
     */
    protected function goLive(): array
    {
        $this->seen = app(LiveFeed::class)->version($this->groupId);

        return [
            'poll' => app(Realtime::class)->pollSeconds(),
            'idle' => LiveFeed::IDLE_SECONDS,
            'websocket' => app(WebsocketClient::class)->settings(LiveFeed::channel($this->groupId)),
        ];
    }

    /**
     * Gives every shown post's comment box, and the open reply box, a value
     * to bind to: the editor's entangle cannot attach to a key that does not
     * exist yet. Called by render().
     *
     * @param  list<FeedPost>  $posts
     */
    protected function keepBoxes(array $posts): void
    {
        foreach ($posts as $post) {
            $this->comments[$post->id] ??= '';
        }

        if ($this->replyingTo !== null) {
            $this->replies[$this->replyingTo] ??= '';
        }
    }

    /** @return list<UploadedFile> */
    protected function chosenImages(): array
    {
        return array_values(array_filter($this->images, static fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    /**
     * Runs $action, showing any refusal under $key.
     *
     * @throws ValidationException
     */
    private function as(string $key, Closure $action): void
    {
        try {
            $action();
        } catch (ValidationException $refused) {
            $message = collect($refused->errors())->flatten()->first();

            throw ValidationException::withMessages([$key => is_string($message) ? $message : __('That did not work. Try again.')]);
        }
    }

    private function postIn(BuildingGroup $group, int $postId): GroupPost
    {
        return GroupPost::query()->published()->where('building_group_id', $group->id)->find($postId)
            ?? throw GroupRefused::because(__('That post is no longer there.'));
    }

    private function commentIn(BuildingGroup $group, int $commentId): GroupComment
    {
        $comment = GroupComment::query()->published()->find($commentId);

        if (! $comment instanceof GroupComment) {
            throw GroupRefused::because(__('That comment is no longer there.'));
        }

        $this->postIn($group, $comment->group_post_id);

        return $comment;
    }

    /** The id of a post or comment of this group. */
    private function itemIn(BuildingGroup $group, ReactableType $type, int $id): int
    {
        return $type === ReactableType::Post ? $this->postIn($group, $id)->id : $this->commentIn($group, $id)->id;
    }
}
