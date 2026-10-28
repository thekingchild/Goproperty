<?php

declare(strict_types=1);

namespace Modules\Billing\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What a metered utility's meter counts (`utility_types.unit_of_measure`,
 * P03-06a): the rate is per one of these.
 */
enum MeterUnit: string
{
    use HasValues;

    case Kwh = 'kwh';
    case CubicMetre = 'cubic_metre';
    case Litre = 'litre';
    case Unit = 'unit';

    /** The name in a sentence ("per kWh"). */
    public function label(): string
    {
        return match ($this) {
            self::Kwh => 'kWh',
            self::CubicMetre => 'm³',
            self::Litre => 'litre',
            self::Unit => 'unit',
        };
    }

    /** The choice in a form. */
    public function optionLabel(): string
    {
        return match ($this) {
            self::Kwh => 'Kilowatt-hours (kWh)',
            self::CubicMetre => 'Cubic metres (m³)',
            self::Litre => 'Litres',
            self::Unit => 'Units (as the meter shows)',
        };
    }
}
