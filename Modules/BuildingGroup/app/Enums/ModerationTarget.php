<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Enums;

use App\Enums\Concerns\HasValues;

/** What a moderation event is about (`group_moderation_events.target_type`; P07-04). */
enum ModerationTarget: string
{
    use HasValues;

    case Post = 'group_post';
    case Comment = 'group_comment';
    case Member = 'group_member';
    case Group = 'building_group';

    public static function of(ReactableType $type): self
    {
        return $type === ReactableType::Post ? self::Post : self::Comment;
    }
}
