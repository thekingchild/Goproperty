<?php

declare(strict_types=1);

namespace Modules\Properties\Enums;

use App\Enums\Concerns\HasValues;

/** What kind of space a unit is, `units.unit_type` (DATABASE_SCHEMA.md § 3). */
enum UnitType: string
{
    use HasValues;

    case Apartment = 'apartment';
    case SelfContain = 'self_contain';
    case Shop = 'shop';
    case Office = 'office';

    public function label(): string
    {
        return match ($this) {
            self::Apartment => 'Flat',
            self::SelfContain => 'Self-contain',
            self::Shop => 'Shop',
            self::Office => 'Office',
        };
    }
}
