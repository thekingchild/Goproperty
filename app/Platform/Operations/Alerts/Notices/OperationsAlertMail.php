<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts\Notices;

use App\Theme\ThemeContext;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An operations alert by email to staff (PLATFORM_CONSOLE.md § 2 "Alerts").
 * Sent at once, never queued: the queue may be what has stopped (on shared
 * hosting it runs inside the scheduler). Staff only, so it may link the
 * console.
 */
final class OperationsAlertMail extends Notification
{
    public function __construct(public readonly AlertNotice $notice) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->notice->title(app(ThemeContext::class)->current()->name()));

        if (in_array($this->notice->kind, [NoticeKind::Raised, NoticeKind::Reminder], true)) {
            $mail->error();
        }

        foreach ($this->notice->lines() as $line) {
            $mail->line($line);
        }

        if ($this->notice->dashboardUrl !== null) {
            $mail->action('Open System operations', $this->notice->dashboardUrl);
        }

        return $mail->line('Where alerts go and when they fire: Settings → Operations alerts in the console.');
    }
}
