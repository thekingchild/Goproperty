<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** DATABASE_SCHEMA.md § 1. */
enum OrganizationStatus: string
{
    use HasValues;

    case Onboarding = 'onboarding';
    case Active = 'active';
    case Restricted = 'restricted';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
