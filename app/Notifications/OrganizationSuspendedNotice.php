<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Theme\ThemeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To each org-owner when staff suspend their organization
 * (ONBOARDING_COMPLIANCE.md § 5.5). Says what still works; the staff
 * member's internal reason stays in the audit log, only their optional note
 * to the owner is quoted.
 */
final class OrganizationSuspendedNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $organizationName,
        public readonly string $organizationSlug,
        public readonly ?string $note = null,
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
        $mail = (new MailMessage)
            ->subject("{$this->organizationName} has been suspended on {$brand}")
            ->line("{$brand} has suspended {$this->organizationName}.")
            ->line('Your team can still sign in, view and export everything, but nothing can be changed until it is reactivated. No data has been deleted.');

        if ($this->note !== null) {
            $mail->line("Note from {$brand}: {$this->note}");
        }

        return $mail
            ->action('Open '.$this->organizationName, route('workspace.home', ['organization' => $this->organizationSlug]))
            ->line("Contact {$brand} support if you have questions or think this is a mistake.");
    }
}
