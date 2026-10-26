<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Models\Organization;
use Illuminate\Support\Str;

/**
 * The organization's web address, /o/{slug} (ONBOARDING_COMPLIANCE.md § 1,
 * § 2): suggested from the name, lower-case letters, digits and single
 * hyphens. A slug is free when no organization uses it now or used it
 * before (old slugs keep redirecting). Words that would make a workspace
 * look official are reserved, as the doc warns about look-alike names.
 */
final class OrganizationSlug
{
    public const int MIN = 3;

    public const int MAX = 48;

    public const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    public const array RESERVED = [
        'admin', 'administrator', 'api', 'app', 'billing', 'compliance', 'goproperty', 'help', 'login', 'new',
        'official', 'owner', 'payments', 'portal', 'root', 'security', 'settings', 'setup', 'signup', 'staff',
        'support', 'system', 'verify', 'www',
    ];

    public static function fromName(string $name): string
    {
        $slug = Str::slug(Str::ascii($name));
        $slug = trim(Str::limit($slug, self::MAX, ''), '-');

        return strlen($slug) >= self::MIN ? $slug : trim($slug.'-org', '-');
    }

    /** A free slug for the name, adding -2, -3, … when needed. */
    public function suggest(string $name): string
    {
        $base = self::fromName($name);

        if ($base === '-org' || $base === 'org') {
            $base = 'my-organization';
        }

        $slug = $base;

        for ($n = 2; ! $this->isAvailable($slug) && $n < 100; $n++) {
            $suffix = '-'.$n;
            $slug = rtrim(substr($base, 0, self::MAX - strlen($suffix)), '-').$suffix;
        }

        return $slug;
    }

    public static function isWellFormed(string $slug): bool
    {
        return strlen($slug) >= self::MIN
            && strlen($slug) <= self::MAX
            && preg_match(self::PATTERN, $slug) === 1
            && ! in_array($slug, self::RESERVED, true);
    }

    public function isAvailable(string $slug): bool
    {
        return self::isWellFormed($slug)
            && ! Organization::query()->withTrashed()->where('slug', $slug)->exists()
            && ! Organization::query()->withTrashed()->whereJsonContains('previous_slugs', $slug)->exists();
    }
}
