<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Posts;

use Modules\BuildingGroup\Models\GroupComment;
use Modules\BuildingGroup\Models\GroupPost;

/**
 * The comments members see under a post (`group_posts.comment_count`):
 * published top-level comments and the published replies of those. Hidden
 * and removed comments (P07-04) are not counted, nor the replies under
 * them. Shared by Posts\GroupPosts and Moderation\GroupModeration.
 *
 * @internal
 */
final class ShownComments
{
    public static function count(GroupPost $post): int
    {
        $top = GroupComment::query()->published()->where('group_post_id', $post->id)->whereNull('parent_id')->pluck('id');

        return $top->count() + GroupComment::query()->published()->where('group_post_id', $post->id)->whereIn('parent_id', $top)->count();
    }
}
