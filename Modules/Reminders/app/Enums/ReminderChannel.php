<?php

declare(strict_types=1);

namespace Modules\Reminders\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where a reminder goes (docs/plans/P05-subtasks.md default 8): by email
 * to an address, or in the app to a portal or workspace account. Each
 * channel is its own reminder row, so a retry never sends one twice. SMS
 * comes with P05-02 and WhatsApp with P05-06.
 */
enum ReminderChannel: string
{
    use HasValues;

    case Email = 'email';
    case InApp = 'in_app';

    /** The Laravel notification channel. */
    public function via(): string
    {
        return match ($this) {
            self::Email => 'mail',
            self::InApp => 'database',
        };
    }
}
