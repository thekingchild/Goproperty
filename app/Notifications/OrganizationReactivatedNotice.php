<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Theme\ThemeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To each org-owner when staff reactivate their organization. */
final class OrganizationReactivatedNotice extends Notification implements ShouldQueue
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
            ->subject("{$this->organizationName} is active again on {$brand}")
            ->line("{$brand} has reactivated {$this->organizationName}. Your team can work as before.");

        if ($this->note !== null) {
            $mail->line("Note from {$brand}: {$this->note}");
        }

        return $mail->action('Open '.$this->organizationName, route('workspace.home', ['organization' => $this->organizationSlug]));
    }
}
