<?php

declare(strict_types=1);

namespace Modules\Leasing\Import;

/**
 * What an imported lease becomes (DATA_IMPORT.md § 3): an ongoing lease
 * (active), history (expired: it ended before the import), or a lease that
 * ended but goes on month by month ("Treat as month-to-month": active,
 * billed monthly, auto-renewing).
 */
enum ImportedLeaseStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case MonthToMonth = 'month_to_month';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::MonthToMonth => 'Month-to-month',
        };
    }

    /** Whether the lease is imported as active (it lets the unit and is billed). */
    public function isOngoing(): bool
    {
        return $this !== self::Expired;
    }

    /** The status as agencies write it ("Ended", "Monthly", "Rolling"…), or null. */
    public static function fromInput(string $text): ?self
    {
        return match ((string) preg_replace('/[^a-z]/', '', mb_strtolower($text))) {
            'active', 'ongoing', 'current', 'running', 'live' => self::Active,
            'expired', 'ended', 'past', 'history', 'finished', 'closed' => self::Expired,
            'monthtomonth', 'monthly', 'rolling', 'monthbymonth', 'holdingover', 'periodic' => self::MonthToMonth,
            default => null,
        };
    }
}
