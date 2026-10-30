<?php

declare(strict_types=1);

namespace Modules\Reminders\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where a reminder goes (docs/plans/P05-subtasks.md defaults 8 and 14): by
 * email to an address, in the app to a portal or workspace account, or by
 * SMS to a verified number (P05-02, on the SMS rungs only), or by the
 * agency's own WhatsApp to a tenant who opted in (P05-06, in the event's
 * channel order before or after SMS). Each channel is its own reminder
 * row, so a retry never sends one twice.
 */
enum ReminderChannel: string
{
    use HasValues;

    case Email = 'email';
    case InApp = 'in_app';
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';

    /** The Laravel notification channel (SMS and WhatsApp go through Core's App\Sms\SmsOutbox and App\WhatsApp instead). */
    public function via(): string
    {
        return match ($this) {
            self::Email => 'mail',
            self::InApp => 'database',
            self::Sms => 'sms',
            self::WhatsApp => 'whatsapp',
        };
    }

    /** SMS or WhatsApp: the channels an event's channel order holds (P05-06). */
    public function isPhone(): bool
    {
        return $this === self::Sms || $this === self::WhatsApp;
    }
}
