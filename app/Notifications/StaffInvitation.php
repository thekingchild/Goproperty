<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Theme\ThemeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * "Invite by email" (PLATFORM_CONSOLE.md § 1): a link to the console where
 * the new staff member sets a password, then enrols 2FA. Sent to staff
 * only, so it may name the console address.
 */
final class StaffInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        #[SensitiveParameter] private readonly string $token,
        private readonly string $invitedBy,
        private readonly int $validDays,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = app(ThemeContext::class)->current()->name();

        return (new MailMessage)
            ->subject("You're invited to the {$brand} staff console")
            ->line("{$this->invitedBy} invited you to the {$brand} staff console.")
            ->line('Set your password, then set up an authenticator app: two-factor authentication is required for staff.')
            ->action('Accept the invitation', route('filament.console.auth.staff-invitation', ['token' => $this->token]))
            ->line("The link works once and expires in {$this->validDays} days. If you did not expect this, ignore this email.");
    }
}
