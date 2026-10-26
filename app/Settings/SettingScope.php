<?php

declare(strict_types=1);

namespace App\Settings;

enum SettingScope: string
{
    /** Platform-wide: theme, website, SEO, sign-in security (PLATFORM_CONSOLE.md § 9). */
    case Global = 'global';

    /** One value per organization. */
    case Organization = 'organization';
}
