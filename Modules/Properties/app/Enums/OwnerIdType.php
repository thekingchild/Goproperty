<?php

declare(strict_types=1);

namespace Modules\Properties\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The identity document on file for a property owner, `owner_profiles.id_type`
 * (DATABASE_SCHEMA.md § 1): a person's NIN (11 digits) or a company's CAC
 * number (RC 1234567), or none. The number itself is encrypted.
 */
enum OwnerIdType: string
{
    use HasValues;

    case Nin = 'nin';
    case Cac = 'cac';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Nin => 'NIN',
            self::Cac => 'CAC number',
            self::None => 'None',
        };
    }

    /** The rule a number of this type must meet, or null when none is kept. */
    public function pattern(): ?string
    {
        return match ($this) {
            self::Nin => '/^\d{11}$/',
            self::Cac => '/^(RC|BN|IT|LP|LLP)? ?\d{1,9}$/',
            self::None => null,
        };
    }
}
