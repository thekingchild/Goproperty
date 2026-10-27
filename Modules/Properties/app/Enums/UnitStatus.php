<?php

declare(strict_types=1);

namespace Modules\Properties\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `units.status` (DATABASE_SCHEMA.md § 3, ARCHITECTURE.md § 6.2): the
 * building page's status board and occupancy counts are read from it.
 *
 * `occupied` and `reserved` follow leases: an allocation is a lease, so
 * only the Leasing module sets them (through Contracts\UnitDirectory::
 * setStatus when a lease is activated, drafted, ended), or an import of
 * ongoing leases. People choose among the manual() statuses only, and not
 * while a lease holds the unit. Whatever the status, a unit counts towards
 * the GoProperty bill until it is archived (BILLING_PAYMENTS.md § 1.4).
 */
enum UnitStatus: string
{
    use HasValues;

    case Vacant = 'vacant';
    case Reserved = 'reserved';
    case Occupied = 'occupied';
    case UnderMaintenance = 'under_maintenance';
    case Unavailable = 'unavailable';

    /** @return list<self> the statuses a person may choose on the unit form */
    public static function manual(): array
    {
        return [self::Vacant, self::UnderMaintenance, self::Unavailable];
    }

    /** Whether a lease holds the unit in this status (set by Leasing only). */
    public function heldByLease(): bool
    {
        return $this === self::Occupied || $this === self::Reserved;
    }

    public function label(): string
    {
        return match ($this) {
            self::Vacant => 'Vacant',
            self::Reserved => 'Reserved',
            self::Occupied => 'Occupied',
            self::UnderMaintenance => 'Under maintenance',
            self::Unavailable => 'Unavailable',
        };
    }

    /** Flux badge colour on the status board. */
    public function color(): string
    {
        return match ($this) {
            self::Vacant => 'amber',
            self::Reserved => 'sky',
            self::Occupied => 'green',
            self::UnderMaintenance => 'orange',
            self::Unavailable => 'zinc',
        };
    }
}
