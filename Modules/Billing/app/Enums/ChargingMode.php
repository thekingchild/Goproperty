<?php

declare(strict_types=1);

namespace Modules\Billing\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How a utility charges (`utility_types.charging_mode`, P03-06a;
 * ARCHITECTURE.md § 6.4): a flat amount each cycle (security levy, waste),
 * or metered: the consumption read from the unit's meter times a rate per
 * unit of measure (electricity, water), from `utility_readings`.
 */
enum ChargingMode: string
{
    use HasValues;

    case Flat = 'flat';
    case Metered = 'metered';

    public function label(): string
    {
        return match ($this) {
            self::Flat => 'Flat amount each cycle',
            self::Metered => 'Metered (per reading)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Flat => 'Flat',
            self::Metered => 'Metered',
        };
    }
}
