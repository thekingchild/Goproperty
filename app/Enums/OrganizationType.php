<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** ONBOARDING_COMPLIANCE.md § 2. */
enum OrganizationType: string
{
    use HasValues;

    case Agency = 'agency';
    case PropertyOwner = 'property_owner';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Agency => 'Agency',
            self::PropertyOwner => 'Property owner',
            self::Hybrid => 'Agency and property owner',
        };
    }
}
