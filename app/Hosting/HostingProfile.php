<?php

declare(strict_types=1);

namespace App\Hosting;

use InvalidArgumentException;

/**
 * The two hosting profiles in ARCHITECTURE.md § 12.1. The profile only sets
 * driver defaults in config; code asks about capabilities (Realtime, the PDF
 * driver, the queue tick), not about the profile.
 */
enum HostingProfile: string
{
    case Shared = 'shared';
    case Vps = 'vps';

    public static function current(): self
    {
        $value = config('hosting.profile');

        return self::tryFrom(is_string($value) ? $value : '')
            ?? throw new InvalidArgumentException(sprintf(
                'HOSTING_PROFILE must be "shared" or "vps", got "%s".',
                is_scalar($value) ? (string) $value : get_debug_type($value),
            ));
    }

    public function label(): string
    {
        return match ($this) {
            self::Shared => 'Shared hosting (Profile A)',
            self::Vps => 'VPS (Profile B)',
        };
    }
}
