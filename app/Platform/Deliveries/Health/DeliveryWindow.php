<?php

declare(strict_types=1);

namespace App\Platform\Deliveries\Health;

use App\Enums\Concerns\HasValues;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The two periods the delivery health dashboard counts (PLATFORM_CONSOLE.md
 * § 2 "Messaging": 24h / 7d), rolling back from now. A delivery belongs to
 * the period it was queued in (`notification_deliveries.queued_at`, the
 * moment GoProperty decided to send it), whatever happened to it since, so
 * a figure never moves from one period to another as its status changes.
 */
enum DeliveryWindow: string
{
    use HasValues;

    case Day = '24h';
    case Week = '7d';

    public function label(): string
    {
        return match ($this) {
            self::Day => 'Last 24 hours',
            self::Week => 'Last 7 days',
        };
    }

    /** The first moment inside the period (inclusive). */
    public function since(CarbonInterface $now): CarbonImmutable
    {
        $now = CarbonImmutable::instance($now);

        return match ($this) {
            self::Day => $now->subHours(24),
            self::Week => $now->subDays(7),
        };
    }
}
