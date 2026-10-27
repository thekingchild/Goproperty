<?php

declare(strict_types=1);

namespace Modules\Properties\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Whether a property owner is a person (first and last name) or a company
 * (company name), `owner_profiles.type` (DATABASE_SCHEMA.md § 1).
 */
enum OwnerType: string
{
    use HasValues;

    case Person = 'person';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Person => 'Person',
            self::Company => 'Company',
        };
    }
}
