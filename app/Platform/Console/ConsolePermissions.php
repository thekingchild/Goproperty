<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Models\PlatformStaff;
use App\Models\StaffStatus;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * Console permissions used so far (PLATFORM_CONSOLE.md § 1). Each settings
 * page checks the permission its keys declare in config/settings.php.
 *
 * Interim rule until staff roles exist (P01-07): every active staff member
 * holds these. Today staff accounts are made only by the installer, as the
 * site's first administrators. P01-07 replaces this with spatie roles on
 * the staff guard (custom code: super-admin only).
 */
final class ConsolePermissions
{
    /** @var list<string> */
    public const array PERMISSIONS = ['settings.theme', 'settings.site', 'settings.seo', 'settings.custom_code'];

    public static function register(Gate $gate): void
    {
        foreach (self::PERMISSIONS as $permission) {
            $gate->define($permission, fn (mixed $user): bool => $user instanceof PlatformStaff && $user->status === StaffStatus::Active);
        }
    }
}
