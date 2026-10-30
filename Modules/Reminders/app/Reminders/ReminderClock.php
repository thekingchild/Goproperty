<?php

declare(strict_types=1);

namespace Modules\Reminders\Reminders;

use Carbon\CarbonImmutable;

/**
 * Reminder days are Lagos calendar days (like bill and lease dates):
 * "today" is the date in Africa/Lagos at midnight in the app's time zone,
 * so it compares with the `date` columns read back from the database.
 * Reminders go from SEND_HOUR Lagos (docs/plans/P05-subtasks.md default 6:
 * no messages at night).
 */
final class ReminderClock
{
    public const string TIMEZONE = 'Africa/Lagos';

    public const string SEND_TIME = '08:00';

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(self::TIMEZONE)->toDateString());
    }

    /** When the reminders of that Lagos day are sent, in the app's time zone. */
    public static function sendAt(CarbonImmutable $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day->toDateString().' '.self::SEND_TIME, self::TIMEZONE)
            ->setTimezone((string) config('app.timezone'));
    }
}
