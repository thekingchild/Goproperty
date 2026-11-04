<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts\Notices;

/** Why an alert notice is sent: a new incident, a reminder that it is still open, its end, or a test from the settings page. */
enum NoticeKind: string
{
    case Raised = 'raised';
    case Reminder = 'reminder';
    case Cleared = 'cleared';
    case Test = 'test';

    public function prefix(): string
    {
        return match ($this) {
            self::Raised => 'Alert',
            self::Reminder => 'Still failing',
            self::Cleared => 'Cleared',
            self::Test => 'Test alert',
        };
    }
}
