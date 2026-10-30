<?php

declare(strict_types=1);

namespace App\Platform\Filament\Widgets\Operations;

use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

/**
 * A widget of Console → Operations → System operations (PLATFORM_CONSOLE.md
 * § 2), refreshed every 60 seconds by its view (`wire:poll.60s`). Not
 * discovered onto the console's home page: the System operations page
 * lists its widgets itself. Each widget says who may see it (canView);
 * the page opens for anyone who may see one of them.
 */
abstract class OperationsWidget extends Widget
{
    public const string TIMEZONE = 'Africa/Lagos';

    protected static bool $isDiscovered = false;

    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = 'full';

    /** A time as the console shows it, in Lagos. */
    public static function lagos(?CarbonImmutable $time): ?string
    {
        return $time?->setTimezone(self::TIMEZONE)->format('j M Y, H:i');
    }
}
