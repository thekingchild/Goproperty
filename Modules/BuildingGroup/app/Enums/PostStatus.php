<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A post's or comment's moderation status (`group_posts.status`,
 * `group_comments.status`; DATABASE_SCHEMA.md § 6). Posts and comments
 * are published; moderators hide them (undone by showing them again) or
 * remove them (final) through Moderation\GroupModeration (P07-04). Both
 * vanish for members and stay for moderators with their trail. An
 * author's own removal is a soft delete instead, gone for everyone.
 */
enum PostStatus: string
{
    use HasValues;

    case Published = 'published';
    case Hidden = 'hidden';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Published => __('Published'),
            self::Hidden => __('Hidden'),
            self::Removed => __('Removed'),
        };
    }
}
