<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Enums;

use App\Enums\Concerns\HasValues;

/** What a reaction is on (`group_reactions.reactable_type`, DATABASE_SCHEMA.md § 6). */
enum ReactableType: string
{
    use HasValues;

    case Post = 'group_post';
    case Comment = 'group_comment';
}
