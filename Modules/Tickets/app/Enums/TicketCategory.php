<?php

declare(strict_types=1);

namespace Modules\Tickets\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What kind of problem a ticket is about (`tickets.category`,
 * DATABASE_SCHEMA.md § 5). The hints give everyday examples so a tenant
 * picks the same category for the same problem.
 */
enum TicketCategory: string
{
    use HasValues;

    case Plumbing = 'plumbing';
    case Electrical = 'electrical';
    case Structural = 'structural';
    case Cleaning = 'cleaning';
    case Security = 'security';
    case Appliance = 'appliance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Plumbing => 'Plumbing',
            self::Electrical => 'Electrical',
            self::Structural => 'Building and structure',
            self::Cleaning => 'Cleaning and waste',
            self::Security => 'Security',
            self::Appliance => 'Appliance',
            self::Other => 'Other',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Plumbing => 'Leaks, blocked drains or toilets, no water, borehole or pumping machine',
            self::Electrical => 'No light, sockets, wiring, prepaid meter, generator changeover',
            self::Structural => 'Cracks, roof leaks, doors, windows, tiles, ceiling',
            self::Cleaning => 'Common areas, refuse, pests, gutters',
            self::Security => 'Gate, locks, fence, guards, intercom',
            self::Appliance => 'Air conditioner, water heater, cooker, fridge supplied with the home',
            self::Other => 'Anything else',
        };
    }
}
