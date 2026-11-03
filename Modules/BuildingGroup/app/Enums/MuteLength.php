<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Enums;

/** How long a moderator mutes a tenant for, in days (P07-04, docs/plans/P07-subtasks.md). */
enum MuteLength: int
{
    case Day = 1;
    case Week = 7;
    case Month = 30;

    public function label(): string
    {
        return match ($this) {
            self::Day => __('1 day'),
            self::Week => __('7 days'),
            self::Month => __('30 days'),
        };
    }
}
