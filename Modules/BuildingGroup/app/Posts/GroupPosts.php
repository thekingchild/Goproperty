<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Posts;

use App\Media\Media;
use App\Media\MediaUploads;
use App\Media\UploadRefused;
use App\Media\UploadValidator;
use App\Models\User;
use App\Plans\Feature;
use App\Plans\PlanGate;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\PostStatus;
use Modules\BuildingGroup\Enums\ReactableType;
use Modules\BuildingGroup\Enums\Reaction;
use Modules\BuildingGroup\Groups\GroupMembership;
use Modules\BuildingGroup\Live\LiveFeed;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupComment;
use Modules\BuildingGroup\Models\GroupPost;
use Modules\BuildingGroup\Models\GroupReaction;

/**
 * The only writer of `group_posts`, `group_comments` and `group_reactions`
 * (ARCHITECTURE.md § 6.8 "How posts, comments and reactions are built";
 * P07-02, docs/plans/P07-subtasks.md defaults 10–24), in the group's
 * organization's context.
 *
 * Who: Policies\BuildingGroupPolicy, by the side the person writes from
 * ($as: Staff from the workspace — `post`, `comment`, `react`,
 * `attachImages`; Tenant from the portal — `postAsTenant`, … — the
 * tenant writing as the tenant record they are a member through), checked
 * before anything is read or written, so the plan switch, a suspended
 * organization and a closed group are refused here too.
 *
 * What:
 * - words are rich text cleaned by Posts\RichText (bold, italic, lists,
 *   links; everything else removed); over 20,000 bytes of HTML is refused,
 *   never cut; a post's text is at most 5,000 characters, a comment's
 *   2,000;
 * - a post has words, images or both; up to 4 images (JPEG, PNG or WebP,
 *   2 MB each, within the media library's pixel caps; every file checked
 *   from its bytes by App\Media\UploadValidator before anything is
 *   written), stored by App\Media\MediaUploads in the post's `group_images`
 *   collection, thumbnails made on the `media` queue;
 * - one level of replies: a reply to a reply is filed under its parent;
 * - one reaction per person and item from the curated set: the same
 *   emoji again takes it back, another replaces it.
 *
 * Each write runs in DB::transaction(…, 3) with the row it hangs on locked
 * (the group for a post, the post for a comment or a reaction) and
 * re-read, so a group closed or a post removed meanwhile wins. Posts and
 * comments, and their removal, are audited (log `building_group`, ids
 * only: never the words). Limits a person, across groups: 10 posts an hour,
 * 60 comments an hour, 120 reactions a minute. Every write tells
 * Live\LiveFeed once it commits, so open pages of the group catch up
 * (P07-03).
 */
final readonly class GroupPosts
{
    public const string LOG = 'building_group';

    /** Images one post may carry (ARCHITECTURE.md § 6.8). */
    public const int MAX_IMAGES = 4;

    /** Characters of a post's text. */
    public const int POST_MAX = 5000;

    /** Characters of a comment's text. */
    public const int COMMENT_MAX = 2000;

    public const int POSTS_PER_HOUR = 10;

    public const int COMMENTS_PER_HOUR = 60;

    public const int REACTIONS_PER_MINUTE = 120;

    public function __construct(
        private RichText $richText,
        private UploadValidator $validator,
        private MediaUploads $uploads,
        private GroupMembership $membership,
        private PlanGate $plans,
        private OrganizationContext $context,
        private LiveFeed $live,
    ) {}

    /**
     * A new post in the group.
     *
     * @param  list<UploadedFile>  $images
     *
     * @throws ValidationException
     */
    public function post(User $by, AuthorKind $as, BuildingGroup $group, ?string $body, array $images = []): GroupPost
    {
        Gate::forUser($by)->authorize($as === AuthorKind::Staff ? 'post' : 'postAsTenant', $group);

        $body = $this->words($body, self::POST_MAX, 'body');

        if ($body === null && $images === []) {
            throw ValidationException::withMessages(['body' => __('Write something or add a photo.')]);
        }

        if ($images !== []) {
            Gate::forUser($by)->authorize($as === AuthorKind::Staff ? 'attachImages' : 'attachImagesAsTenant', $group);
            $this->checkImages($images);
        }

        $this->limit('group-posts:'.$by->id, self::POSTS_PER_HOUR, 3600, 'body', __('You have posted a lot this hour. Try again in :minutes minutes.'));

        return DB::transaction(function () use ($by, $as, $group, $body, $images): GroupPost {
            $group = $this->lockedGroup($group->id);
            $this->plans->ensureFeature(Feature::BuildingGroup, $this->context->get());
            $now = CarbonImmutable::now();

            $post = new GroupPost;
            $post->building_group_id = $group->id;
            $post->author_kind = $as;
            $post->author_user_id = $by->id;
            $post->author_tenant_profile_id = $this->tenantRecord($by, $as, $group);
            $post->body = $body;
            $post->status = PostStatus::Published;
            $post->last_activity_at = $now;
            $post->save();

            $stored = [];

            foreach ($images as $image) {
                $media = $this->uploads->add($post, GroupPost::IMAGES, $image, null, ['building_group_id' => $group->id, 'group_post_id' => $post->id]);
                $stored[] = ['media_id' => $media->id, 'file_sha256' => (string) $media->checksum()];
            }

            if ($stored !== []) {
                $post->image_count = count($stored);
                $post->save();
            }

            activity(self::LOG)
                ->event('group.post_published')
                ->performedOn($post)
                ->causedBy($by)
                ->withProperties([
                    'building_group_id' => $group->id,
                    'property_id' => $group->property_id,
                    'author' => $as->value,
                    'tenant_profile_id' => $post->author_tenant_profile_id,
                    'images' => $stored,
                ])
                ->log('Post published in the Building Group');

            $this->live->changed($group->id);

            return $post;
        }, 3);
    }

    /**
     * A comment on the post, or with $replyTo a reply to one of its
     * comments (filed under the top-level comment).
     *
     * @throws ValidationException
     */
    public function comment(User $by, AuthorKind $as, GroupPost $post, ?string $body, ?int $replyTo = null): GroupComment
    {
        $group = $this->groupOf($post);
        Gate::forUser($by)->authorize($as === AuthorKind::Staff ? 'comment' : 'commentAsTenant', $group);

        $body = $this->words($body, self::COMMENT_MAX, 'comment');

        if ($body === null) {
            throw ValidationException::withMessages(['comment' => __('Write your comment first.')]);
        }

        $this->limit('group-comments:'.$by->id, self::COMMENTS_PER_HOUR, 3600, 'comment', __('You have commented a lot this hour. Try again in :minutes minutes.'));

        return DB::transaction(function () use ($by, $as, $group, $post, $body, $replyTo): GroupComment {
            $post = $this->lockedPost($post->id);
            $this->plans->ensureFeature(Feature::BuildingGroup, $this->context->get());
            $parentId = $replyTo === null ? null : $this->parentFor($post, $replyTo);

            $comment = new GroupComment;
            $comment->group_post_id = $post->id;
            $comment->parent_id = $parentId;
            $comment->author_kind = $as;
            $comment->author_user_id = $by->id;
            $comment->author_tenant_profile_id = $this->tenantRecord($by, $as, $group);
            $comment->body = $body;
            $comment->status = PostStatus::Published;
            $comment->save();

            $post->comment_count = $this->visibleComments($post);
            $post->last_activity_at = CarbonImmutable::now();
            $post->save();

            activity(self::LOG)
                ->event('group.comment_posted')
                ->performedOn($comment)
                ->causedBy($by)
                ->withProperties([
                    'building_group_id' => $group->id,
                    'group_post_id' => $post->id,
                    'parent_id' => $parentId,
                    'author' => $as->value,
                    'tenant_profile_id' => $comment->author_tenant_profile_id,
                ])
                ->log($parentId === null ? 'Comment posted in the Building Group' : 'Reply posted in the Building Group');

            $this->live->changed($group->id);

            return $comment;
        }, 3);
    }

    /**
     * Reacts to a post or comment: the person's reaction now, or null when
     * the same emoji again took it back.
     *
     * @throws ValidationException
     */
    public function react(User $by, AuthorKind $as, ReactableType $type, int $id, Reaction $emoji): ?Reaction
    {
        [$post] = $this->item($type, $id);
        $group = $this->groupOf($post);
        Gate::forUser($by)->authorize($as === AuthorKind::Staff ? 'react' : 'reactAsTenant', $group);

        $this->limit('group-reactions:'.$by->id, self::REACTIONS_PER_MINUTE, 60, 'group', __('Too many reactions. Try again in a minute.'));

        return DB::transaction(function () use ($by, $type, $id, $emoji, $post): ?Reaction {
            $this->lockedPost($post->id);
            $this->plans->ensureFeature(Feature::BuildingGroup, $this->context->get());
            // The comment may have gone while the post was waiting.
            $this->item($type, $id);
            $this->live->changed($post->building_group_id);

            $reaction = GroupReaction::query()
                ->where('user_id', $by->id)
                ->where('reactable_type', $type)
                ->where('reactable_id', $id)
                ->first();

            if ($reaction instanceof GroupReaction && $reaction->emoji === $emoji) {
                // A toggle, not a record (DATABASE_SCHEMA.md § 6 `group_reactions`).
                $reaction->delete();

                return null;
            }

            $reaction ??= new GroupReaction;
            $reaction->user_id = $by->id;
            $reaction->reactable_type = $type;
            $reaction->reactable_id = $id;
            $reaction->emoji = $emoji;
            $reaction->save();

            return $emoji;
        }, 3);
    }

    /**
     * The author removes their post: it vanishes for everyone (soft
     * delete), with its comments, and its images are deleted for good
     * after the commit (attachments: a photo posted by mistake must really
     * go; ARCHITECTURE.md § 9), their sha256 kept in the audit entry.
     */
    public function deletePost(User $by, GroupPost $post): void
    {
        Gate::forUser($by)->authorize('deletePost', $post);

        $images = DB::transaction(function () use ($by, $post): array {
            $post = $this->lockedPost($post->id);
            $images = $post->getMedia(GroupPost::IMAGES)->all();
            $post->delete();

            activity(self::LOG)
                ->event('group.post_deleted')
                ->performedOn($post)
                ->causedBy($by)
                ->withProperties([
                    'building_group_id' => $post->building_group_id,
                    'images_deleted' => array_map(static fn (Media $media): array => ['media_uuid' => $media->uuid, 'file_sha256' => (string) $media->checksum()], array_values(array_filter($images, static fn (mixed $media): bool => $media instanceof Media))),
                ])
                ->log('Post removed from the Building Group by its author');

            $this->live->changed($post->building_group_id);

            return $images;
        }, 3);

        foreach ($images as $image) {
            // The media library removes the row and its files, conversions included.
            $image->delete();
        }
    }

    /** The author removes their comment; the replies under it go with it (soft delete). */
    public function deleteComment(User $by, GroupComment $comment): void
    {
        Gate::forUser($by)->authorize('deleteComment', $comment);

        DB::transaction(function () use ($by, $comment): void {
            $post = $this->lockedPost($comment->group_post_id);
            $comment = GroupComment::query()->whereKey($comment->id)->where('group_post_id', $post->id)->first()
                ?? throw GroupRefused::because(__('That comment is no longer there.'));

            GroupComment::query()->where('group_post_id', $post->id)->where('parent_id', $comment->id)->get()
                ->each(static fn (GroupComment $reply): ?bool => $reply->delete());
            $comment->delete();

            $post->comment_count = $this->visibleComments($post);
            $post->save();

            activity(self::LOG)
                ->event('group.comment_deleted')
                ->performedOn($comment)
                ->causedBy($by)
                ->withProperties(['building_group_id' => $post->building_group_id, 'group_post_id' => $post->id])
                ->log('Comment removed from the Building Group by its author');

            $this->live->changed($post->building_group_id);
        }, 3);
    }

    /**
     * Clean words, or null when there are none.
     *
     * @throws ValidationException when they are too long
     */
    private function words(?string $html, int $max, string $key): ?string
    {
        if ($html !== null && strlen($html) > RichText::MAX_INPUT_BYTES) {
            throw ValidationException::withMessages([$key => __('This is too long. Shorten it and try again.')]);
        }

        $clean = $this->richText->clean($html);

        if ($clean !== null && RichText::length($clean) > $max) {
            throw ValidationException::withMessages([$key => __('Keep it to :max characters (this has :count).', ['max' => number_format($max), 'count' => number_format(RichText::length($clean))])]);
        }

        return $clean;
    }

    /**
     * @param  list<UploadedFile>  $images
     *
     * @throws ValidationException
     */
    private function checkImages(array $images): void
    {
        if (count($images) > self::MAX_IMAGES) {
            throw ValidationException::withMessages(['images' => __('Add at most :max photos to a post.', ['max' => self::MAX_IMAGES])]);
        }

        foreach ($images as $image) {
            try {
                $this->validator->check(GroupPost::IMAGES, $image);
            } catch (UploadRefused $refused) {
                throw ValidationException::withMessages(['images' => $refused->getMessage()]);
            }
        }
    }

    /** @throws ValidationException when the person went over the limit */
    private function limit(string $key, int $max, int $seconds, string $field, string $message): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $wait = max(1, RateLimiter::availableIn($key));

            throw GroupRefused::because(str_replace(':minutes', (string) max(1, (int) ceil($wait / 60)), $message), $field);
        }

        RateLimiter::hit($key, $seconds);
    }

    /** The tenant record a tenant writes as (their membership), re-read in the transaction; null for staff. */
    private function tenantRecord(User $by, AuthorKind $as, BuildingGroup $group): ?int
    {
        if ($as === AuthorKind::Staff) {
            return null;
        }

        $this->membership->forget();

        return $this->membership->tenantProfileFor($by, $group)
            ?? throw GroupRefused::because(__('You are no longer a member of this group.'));
    }

    private function lockedGroup(int $groupId): BuildingGroup
    {
        $group = BuildingGroup::query()->whereKey($groupId)->lockForUpdate()->first();

        if (! $group instanceof BuildingGroup || ! $group->is_active) {
            throw GroupRefused::because(__('This group is closed: its building is no longer managed here.'));
        }

        return $group;
    }

    private function lockedPost(int $postId): GroupPost
    {
        $post = GroupPost::query()->whereKey($postId)->lockForUpdate()->first();

        if (! $post instanceof GroupPost || $post->status !== PostStatus::Published) {
            throw GroupRefused::because(__('That post is no longer there.'));
        }

        return $post;
    }

    private function groupOf(GroupPost $post): BuildingGroup
    {
        return BuildingGroup::query()->find($post->building_group_id)
            ?? throw GroupRefused::because(__('That post is no longer there.'));
    }

    /**
     * The published post a reaction is on, and the comment when it is on one.
     *
     * @return array{0: GroupPost, 1: GroupComment|null}
     */
    private function item(ReactableType $type, int $id): array
    {
        if ($type === ReactableType::Post) {
            $post = GroupPost::query()->published()->find($id);

            return $post instanceof GroupPost ? [$post, null] : throw GroupRefused::because(__('That post is no longer there.'));
        }

        $comment = GroupComment::query()->published()->find($id);
        $post = $comment instanceof GroupComment ? GroupPost::query()->published()->find($comment->group_post_id) : null;

        if (! $comment instanceof GroupComment || ! $post instanceof GroupPost || ! $this->commentShown($comment)) {
            throw GroupRefused::because(__('That comment is no longer there.'));
        }

        return [$post, $comment];
    }

    /** A reply shows only while its parent does. */
    private function commentShown(GroupComment $comment): bool
    {
        return $comment->parent_id === null
            || GroupComment::query()->published()->whereKey($comment->parent_id)->exists();
    }

    /** The top-level comment a reply goes under (one level of replies). */
    private function parentFor(GroupPost $post, int $replyTo): int
    {
        $target = GroupComment::query()->published()->where('group_post_id', $post->id)->find($replyTo);

        if (! $target instanceof GroupComment || ! $this->commentShown($target)) {
            throw GroupRefused::because(__('That comment is no longer there.'), 'comment');
        }

        return $target->parent_id ?? $target->id;
    }

    /** Published comments shown under the post: top-level ones and the replies of those. */
    private function visibleComments(GroupPost $post): int
    {
        $top = GroupComment::query()->published()->where('group_post_id', $post->id)->whereNull('parent_id')->pluck('id');

        return $top->count() + GroupComment::query()->published()->where('group_post_id', $post->id)->whereIn('parent_id', $top)->count();
    }
}
