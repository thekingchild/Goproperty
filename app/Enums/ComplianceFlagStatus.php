<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where a compliance flag stands (P06-09, `compliance_flags.status`): open,
 * acknowledged by a staff member, resolved (by staff with a note, or by the
 * monitoring when the measure fell back), or dismissed by staff as nothing
 * to act on.
 */
enum ComplianceFlagStatus: string
{
    use HasValues;

    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Acknowledged => 'Acknowledged',
            self::Resolved => 'Resolved',
            self::Dismissed => 'Dismissed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Acknowledged => 'warning',
            self::Resolved => 'success',
            self::Dismissed => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open || $this === self::Acknowledged;
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Open->value, self::Acknowledged->value];
    }
}
