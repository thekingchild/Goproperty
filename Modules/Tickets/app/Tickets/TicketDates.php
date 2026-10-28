<?php

declare(strict_types=1);

namespace Modules\Tickets\Tickets;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** How ticket times are shown: in Lagos (AGENT.md "Lagos time for dates shown"). */
final class TicketDates
{
    public const string TIMEZONE = 'Africa/Lagos';

    /** "4 Jan 2027, 14:05" in Lagos, or "—". */
    public static function show(?DateTimeInterface $at): string
    {
        return $at === null ? '—' : CarbonImmutable::instance($at)->setTimezone(self::TIMEZONE)->format('j M Y, H:i');
    }

    /** "4 Jan 2027" in Lagos, or "—". */
    public static function day(?DateTimeInterface $at): string
    {
        return $at === null ? '—' : CarbonImmutable::instance($at)->setTimezone(self::TIMEZONE)->format('j M Y');
    }
}
