<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Posts;

use Illuminate\Validation\ValidationException;

/**
 * A Building Group write that cannot happen: the group closed meanwhile,
 * the post or comment is gone, too many posts this hour. Nothing was
 * changed. A ValidationException (key `group` unless said otherwise), so
 * the pages show the message where the action is.
 */
final class GroupRefused extends ValidationException
{
    public static function because(string $message, string $key = 'group'): self
    {
        return self::withMessages([$key => $message]);
    }
}
