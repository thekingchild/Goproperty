<?php

declare(strict_types=1);

namespace Modules\Reminders\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where a reminder goes (docs/plans/P05-subtasks.md defaults 8 and 14): by
 * email to an address, in the app to a portal or workspace account, or by
 * SMS to a verified number (P05-02, on the SMS rungs only). Each channel
 * is its own reminder row, so a retry never sends one twice. WhatsApp
 * comes with P05-06.
 */
enum ReminderChannel: string
{
    use HasValues;

    case Email = 'email';
    case InApp = 'in_app';
    case Sms = 'sms';

    /** The Laravel notification channel (SMS goes through Core's App\Sms\SmsOutbox instead). */
    public function via(): string
    {
        return match ($this) {
            self::Email => 'mail',
            self::InApp => 'database',
            self::Sms => 'sms',
        };
    }
}
