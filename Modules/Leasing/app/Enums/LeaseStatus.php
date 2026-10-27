<?php

declare(strict_types=1);

namespace Modules\Leasing\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A lease's state (`leases.status`, DATABASE_SCHEMA.md § 3; ARCHITECTURE.md
 * § 6.3). Changed only by Modules\Leasing\Leases\LeaseService (AGENT.md § 3
 * rule 5):
 *
 *     draft ──activate──▶ active          (the unit is let; contract number given)
 *     draft ──cancel────▶ terminated      (a draft nobody signs)
 *     draft ──lapse─────▶ expired         (its hold on the unit ran out: leases:expire-and-flag)
 *     active ─terminate─▶ terminated      (ended early, with a reason)
 *     active ─end date──▶ expired         (leases:expire-and-flag, after the end date)
 *     active ─renewal───▶ renewed         (its renewal lease was activated)
 *
 * Expired, terminated and renewed are final: a lease is never brought back
 * (a new lease, or a renewal, is a new record). Every other move is
 * refused and changes nothing.
 */
enum LeaseStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Active = 'active';
    case Expired = 'expired';
    case Terminated = 'terminated';
    case Renewed = 'renewed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Terminated => 'Terminated',
            self::Renewed => 'Renewed',
        };
    }

    /** Flux badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'sky',
            self::Active => 'green',
            self::Expired => 'zinc',
            self::Terminated => 'red',
            self::Renewed => 'indigo',
        };
    }

    /** The moves the state machine allows (see the class comment). */
    public function canMoveTo(self $next): bool
    {
        return match ($this) {
            self::Draft => in_array($next, [self::Active, self::Terminated, self::Expired], true),
            self::Active => in_array($next, [self::Terminated, self::Expired, self::Renewed], true),
            self::Expired, self::Terminated, self::Renewed => false,
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Expired, self::Terminated, self::Renewed], true);
    }

    /** Whether the lease has ended (its deposit may be refunded). */
    public function hasEnded(): bool
    {
        return $this === self::Expired || $this === self::Terminated;
    }
}
