<?php

declare(strict_types=1);

namespace Modules\Handovers\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an organization's owners about a change of a management handover
 * (BILLING_PAYMENTS.md § 7.7 rule 3: both sides, every state change, by
 * email and in the app): offered (to the offering side), accepted,
 * declined, withdrawn, expired, notice given, notice withdrawn, ended, and
 * a tenant's credit settled. The link opens the handover's page in that
 * organization's workspace. Never SMS or WhatsApp. Queued on
 * `notifications`, after the commit.
 */
final class HandoverChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<string>  $lines */
    public function __construct(
        public readonly string $event,
        public readonly int $handoverId,
        public readonly string $slug,
        public readonly string $subject,
        public readonly array $lines,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function url(): string
    {
        return route('workspace.handovers.show', ['organization' => $this->slug, 'handover' => $this->handoverId]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject);

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail->action('Open the handover', $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'handover_id' => $this->handoverId,
            'subject' => $this->subject,
            'url' => $this->url(),
        ];
    }
}
