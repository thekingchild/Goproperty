<?php

declare(strict_types=1);

namespace App\Support;

/** The 36 states and the Federal Capital Territory, for address fields. */
final class NigerianStates
{
    public const array ALL = [
        'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno', 'Cross River', 'Delta',
        'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'FCT', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi',
        'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto',
        'Taraba', 'Yobe', 'Zamfara',
    ];

    public static function label(string $state): string
    {
        return $state === 'FCT' ? 'Federal Capital Territory (Abuja)' : $state;
    }
}
