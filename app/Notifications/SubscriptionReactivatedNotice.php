<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Theme\ThemeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To each org-owner when staff reactivate their organization's cancelled
 * subscription (P06-07a; docs/plans/P06-subtasks.md default 52). The new
 * invoice (from today, BILLING_PAYMENTS.md § 3) is sent on its own by
 * SaasBilling; whether the team can work again depends on whether the
 * subscription was what closed the organization (a suspension by staff is
 * lifted by staff).
 */
final class SubscriptionReactivatedNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $organizationName,
        public readonly string $organizationSlug,
        public readonly bool $reopened,
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
            ->subject("The {$brand} subscription of {$this->organizationName} is active again")
            ->line("{$brand} has reactivated the subscription of {$this->organizationName}."
                .($this->reopened ? ' Your team can work as before.' : ''))
            ->line('Billing starts again: each invoice is emailed when it is issued, as before.');

        if ($this->note !== null) {
            $mail->line("Note from {$brand}: {$this->note}");
        }

        return $mail->action('Open '.$this->organizationName, route('workspace.home', ['organization' => $this->organizationSlug]));
    }
}
