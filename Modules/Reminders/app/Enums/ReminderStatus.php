<?php

declare(strict_types=1);

namespace Modules\Reminders\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A reminder's state (`reminders.status`): planned and waiting for its send
 * time (`pending`), handed to the mailer or written in the app (`sent`), or
 * not sent, with the reason in `skip_reason` (`skipped`).
 */
enum ReminderStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Sent = 'sent';
    case Skipped = 'skipped';
}
