<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Theme\ThemeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To each org-owner when staff cancel their organization's subscription
 * (P06-07a; docs/plans/P06-subtasks.md default 52), like the suspension
 * notices: what still works, the staff member's optional note quoted, never
 * the internal reason (that stays in the audit log).
 */
final class SubscriptionCancelledNotice extends Notification implements ShouldQueue
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
            ->subject("The {$brand} subscription of {$this->organizationName} is cancelled")
            ->line("{$brand} has cancelled the subscription of {$this->organizationName}.")
            ->line('Your team can still sign in, view and export everything, but nothing can be changed. No data has been deleted, and no new invoice will be issued.');

        if ($this->note !== null) {
            $mail->line("Note from {$brand}: {$this->note}");
        }

        return $mail
            ->action('Open '.$this->organizationName, route('workspace.home', ['organization' => $this->organizationSlug]))
            ->line("Contact {$brand} support to reactivate it or if you think this is a mistake.");
    }
}
