<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A post's or comment's moderation status (`group_posts.status`,
 * `group_comments.status`; DATABASE_SCHEMA.md § 6). Every post and comment
 * is published in P07-02; moderators hide and remove them in P07-04.
 */
enum PostStatus: string
{
    use HasValues;

    case Published = 'published';
    case Hidden = 'hidden';
    case Removed = 'removed';
}
