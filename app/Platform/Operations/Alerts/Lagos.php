<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts;

use Carbon\CarbonInterface;

/** Times in alerts, as staff read them: Lagos time. */
final class Lagos
{
    public static function time(?CarbonInterface $time): string
    {
        return $time === null ? 'never' : $time->copy()->setTimezone('Africa/Lagos')->format('j M Y, H:i').' (Lagos)';
    }

    /** "1 h 5 min", "12 min" */
    public static function duration(CarbonInterface $from, CarbonInterface $to): string
    {
        $minutes = intdiv((int) abs($to->getTimestamp() - $from->getTimestamp()), 60);

        return match (true) {
            $minutes < 60 => $minutes.' min',
            $minutes < 1440 => intdiv($minutes, 60).' h'.($minutes % 60 > 0 ? ' '.($minutes % 60).' min' : ''),
            default => intdiv($minutes, 1440).' d'.(intdiv($minutes % 1440, 60) > 0 ? ' '.intdiv($minutes % 1440, 60).' h' : ''),
        };
    }
}
