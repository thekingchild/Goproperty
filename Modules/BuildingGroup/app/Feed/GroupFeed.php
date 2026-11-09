<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Feed;

use App\Branding\Branding;
use App\Media\Media;
use App\Media\MediaUrls;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Collection;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\PostStatus;
use Modules\BuildingGroup\Enums\ReactableType;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupComment;
use Modules\BuildingGroup\Models\GroupPost;
use Modules\BuildingGroup\Models\GroupReaction;
use Modules\BuildingGroup\Posts\RichText;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * A group's posts as its pages show them (P07-02), in the group's
 * organization's context, for a viewer the page has already let in
 * (`view` or `viewAsTenant`): published posts, the most recently active
 * first (a comment brings a post up), each with its author, its words
 * (cleaned again on the way out: Posts\RichText), its images (signed
 * links, only when $images: never while GoProperty staff impersonate),
 * the reactions and the viewer's own, and its published comments, oldest
 * first, with their replies. Removed posts and comments (their authors'
 * soft deletes) are not read; a reply shows only under a shown comment.
 * Pinned posts come first, the newest pin first (P07-04).
 *
 * For a moderator ($moderator: `viewModeration`, P07-04) the feed also
 * holds the posts and comments moderators hid or removed, each with its
 * status and trail, and every item's open reports; members never get
 * them (a hidden post's comments, a hidden comment's replies vanish with
 * it). Everyone sees whether they have reported an item.
 *
 * Names (docs/plans/P07-subtasks.md default 16): a tenant by the agency's
 * record of them, first name and the initial of their surname ("Kemi A.");
 * a member of staff by their name, with the agency's name beside it. No
 * unit, phone or email.
 */
final readonly class GroupFeed
{
    /** Posts a page shows at first; "Show more" adds as many again. */
    public const int PAGE = 20;

    public function __construct(
        private RichText $richText,
        private TenantDirectory $tenants,
        private MediaUrls $urls,
        private OrganizationContext $context,
    ) {}

    /**
     * The first $limit posts, and whether there are more.
     *
     * @return array{0: list<FeedPost>, 1: bool}
     */
    public function page(BuildingGroup $group, User $viewer, int $limit, bool $images, bool $moderator = false): array
    {
        $posts = GroupPost::query()
            ->when($moderator, static fn ($query) => $query->moderatable(), static fn ($query) => $query->published())
            ->where('building_group_id', $group->id)
            ->orderByRaw('pinned_at is null')
            ->orderByDesc('pinned_at')
            ->orderByDesc('last_activity_at')->orderByDesc('id')
            ->limit($limit + 1)
            ->get();
        $more = $posts->count() > $limit;
        $posts = $posts->take($limit)->values();

        if ($posts->isEmpty()) {
            return [[], false];
        }

        $comments = $this->comments(self::ids($posts), $moderator);
        $reactions = $this->reactions(self::ids($posts), self::ids($comments), $viewer);
        $moderation = ModerationView::load(self::ids($posts), self::ids($comments), $viewer, $moderator);
        $names = $this->names($posts, $comments);
        $media = $images ? $this->media($posts) : collect();
        $mayDelete = new DeletePermission($viewer);

        $feed = [];
        // Grouped once, oldest first within each: filtering every comment of
        // the page per post and per comment added up (P09-03).
        $byPost = $comments->groupBy('group_post_id');
        $byParent = $comments->whereNotNull('parent_id')->groupBy('parent_id');

        foreach ($posts as $post) {
            $top = $byPost->get($post->id, collect())->whereNull('parent_id');
            $commentRows = [];

            foreach ($top as $comment) {
                $replies = [];

                foreach ($byParent->get($comment->id, collect())->where('group_post_id', $post->id) as $reply) {
                    $replies[] = $this->comment($reply, $viewer, $names, $reactions, $mayDelete, $moderation, []);
                }

                $commentRows[] = $this->comment($comment, $viewer, $names, $reactions, $mayDelete, $moderation, $replies);
            }

            $mine = $post->author_user_id === $viewer->id;
            [$author, $badge] = $names->name($post->author_kind, $post->author_user_id, $post->author_tenant_profile_id);

            $feed[] = new FeedPost(
                id: $post->id,
                author: $author,
                badge: $badge,
                mine: $mine,
                html: $this->richText->clean($post->body),
                at: $post->created_at,
                imageCount: $post->image_count,
                images: array_values(array_map(fn (Media $image): FeedImage => new FeedImage(
                    thumbUrl: $this->urls->preview($image, 'thumb'),
                    fullUrl: $this->urls->preview($image, 'large'),
                ), ($media[$post->id] ?? collect())->all())),
                reactions: $reactions->counts(ReactableType::Post, $post->id),
                myReaction: $reactions->mine(ReactableType::Post, $post->id),
                comments: $commentRows,
                commentCount: count($commentRows) + array_sum(array_map(static fn (FeedComment $row): int => count($row->replies), $commentRows)),
                canDelete: $mine && $post->status === PostStatus::Published && $mayDelete->post($post),
                status: $post->status,
                pinned: $post->pinned_at !== null,
                fromTenant: $post->author_kind === AuthorKind::Tenant,
                trail: $moderation->trail(ReactableType::Post, $post->id),
                openReports: $moderation->openReports(ReactableType::Post, $post->id),
                reportedByMe: $moderation->reportedByViewer(ReactableType::Post, $post->id),
            );
        }

        return [$feed, $more];
    }

    /** @param  list<FeedComment>  $replies */
    private function comment(GroupComment $comment, User $viewer, AuthorNames $names, ReactionTally $reactions, DeletePermission $mayDelete, ModerationView $moderation, array $replies): FeedComment
    {
        $mine = $comment->author_user_id === $viewer->id;
        [$author, $badge] = $names->name($comment->author_kind, $comment->author_user_id, $comment->author_tenant_profile_id);

        return new FeedComment(
            id: $comment->id,
            author: $author,
            badge: $badge,
            mine: $mine,
            html: (string) $this->richText->clean($comment->body),
            at: $comment->created_at,
            reactions: $reactions->counts(ReactableType::Comment, $comment->id),
            myReaction: $reactions->mine(ReactableType::Comment, $comment->id),
            replies: $replies,
            canDelete: $mine && $comment->status === PostStatus::Published && $mayDelete->comment($comment),
            status: $comment->status,
            fromTenant: $comment->author_kind === AuthorKind::Tenant,
            trail: $moderation->trail(ReactableType::Comment, $comment->id),
            openReports: $moderation->openReports(ReactableType::Comment, $comment->id),
            reportedByMe: $moderation->reportedByViewer(ReactableType::Comment, $comment->id),
        );
    }

    /**
     * @param  Collection<int, GroupPost>|Collection<int, GroupComment>  $rows
     * @return list<int>
     */
    private static function ids(Collection $rows): array
    {
        return array_values($rows->map(static fn (GroupPost|GroupComment $row): int => $row->id)->all());
    }

    /**
     * Published comments of the posts (for a moderator, hidden and removed
     * ones too), oldest first; replies only under a shown comment.
     *
     * @param  list<int>  $postIds
     * @return Collection<int, GroupComment>
     */
    private function comments(array $postIds, bool $moderator): Collection
    {
        $comments = GroupComment::query()
            ->when($moderator, static fn ($query) => $query->moderatable(), static fn ($query) => $query->published())
            ->whereIn('group_post_id', $postIds)->orderBy('id')->get();
        $shown = self::ids($comments->whereNull('parent_id'));

        return $comments->filter(static fn (GroupComment $comment): bool => $comment->parent_id === null || in_array($comment->parent_id, $shown, true))->values();
    }

    /**
     * @param  list<int>  $postIds
     * @param  list<int>  $commentIds
     */
    private function reactions(array $postIds, array $commentIds, User $viewer): ReactionTally
    {
        $rows = GroupReaction::query()
            ->where(static function ($query) use ($postIds, $commentIds): void {
                $query->where(static fn ($posts) => $posts->where('reactable_type', ReactableType::Post)->whereIn('reactable_id', $postIds));

                if ($commentIds !== []) {
                    $query->orWhere(static fn ($comments) => $comments->where('reactable_type', ReactableType::Comment)->whereIn('reactable_id', $commentIds));
                }
            })
            ->get(['user_id', 'reactable_type', 'reactable_id', 'emoji']);

        return new ReactionTally($rows, $viewer->id);
    }

    /**
     * @param  Collection<int, GroupPost>  $posts
     * @param  Collection<int, GroupComment>  $comments
     */
    private function names(Collection $posts, Collection $comments): AuthorNames
    {
        $tenantIds = [];
        $userIds = [];

        foreach ([...$posts->all(), ...$comments->all()] as $item) {
            if ($item->author_kind === AuthorKind::Tenant && $item->author_tenant_profile_id !== null) {
                $tenantIds[] = $item->author_tenant_profile_id;
            } elseif ($item->author_user_id !== null) {
                $userIds[] = $item->author_user_id;
            }
        }

        $tenants = array_map(static fn ($tenant): string => $tenant->name, $this->tenants->findMany(array_values(array_unique($tenantIds))));
        /** @var array<int, string> $staff */
        $staff = $userIds === [] ? [] : User::query()->whereIn('id', array_unique($userIds))->pluck('name', 'id')->all();

        return new AuthorNames($tenants, $staff, Branding::fromOrganization($this->context->get())->displayName());
    }

    /**
     * The posts' images, by post, in the order they were added.
     *
     * @param  Collection<int, GroupPost>  $posts
     * @return Collection<int, Collection<int, Media>>
     */
    private function media(Collection $posts): Collection
    {
        $withImages = $posts->filter(static fn (GroupPost $post): bool => $post->image_count > 0);

        if ($withImages->isEmpty()) {
            return collect();
        }

        /** @var Collection<int, Collection<int, Media>> */
        return Media::query()
            ->where('model_type', (new GroupPost)->getMorphClass())
            ->where('collection_name', GroupPost::IMAGES)
            ->whereIn('model_id', self::ids($withImages))
            ->orderBy('order_column')->orderBy('id')
            ->get()
            ->groupBy('model_id');
    }
}
