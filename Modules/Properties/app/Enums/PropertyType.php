<?php

declare(strict_types=1);

namespace Modules\Properties\Enums;

use App\Enums\Concerns\HasValues;

/** What kind of building it is, `properties.property_type` (DATABASE_SCHEMA.md § 3). */
enum PropertyType: string
{
    use HasValues;

    case BlockOfFlats = 'block_of_flats';
    case Estate = 'estate';
    case Duplex = 'duplex';
    case Bungalow = 'bungalow';
    case Commercial = 'commercial';
    case Mixed = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::BlockOfFlats => 'Block of flats',
            self::Estate => 'Estate',
            self::Duplex => 'Duplex',
            self::Bungalow => 'Bungalow',
            self::Commercial => 'Commercial',
            self::Mixed => 'Mixed use',
        };
    }
}
