<?php

declare(strict_types=1);

namespace Modules\Reminders\Notifications;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Reminders\Models\Reminder;

/**
 * A reminder (P05-01; ARCHITECTURE.md § 6.7): rent or a utility bill due,
 * a bill overdue, or (to staff) a lease ending. One channel per
 * notification, as the reminder row says: by email in the agency's name
 * (BRANDING_DOCUMENTS.md § 1: sender "Adeyemi Realty via GoProperty" or
 * the agency's own, replies to the agency's email), or in the app. Built
 * from plain strings by Reminders\ReminderSender. Queued on
 * `notifications` after the commit; a retried job sends nothing twice:
 * the in-app row's id is the reminder's `notification_id`, and the email
 * is skipped once the reminder says it went (`delivered_at`,
 * Listeners\MarkReminderDelivered).
 */
final class ReminderNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @param  list<string>  $lines */
    public function __construct(
        public readonly int $reminderId,
        public readonly string $type,
        public readonly int $organizationId,
        public readonly string $subject,
        public readonly array $lines,
        public readonly ?string $url,
        public readonly string $actionLabel,
        public readonly string $agency,
        public readonly string $greetingName,
        public readonly string $senderName,
        public readonly ?string $replyTo,
        public readonly string $channel,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<int> seconds before each retry */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [$this->channel];
    }

    /** A retried job sends nothing twice (see the class comment). */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return match ($channel) {
            'database' => ! DatabaseNotification::query()->whereKey($this->id)->exists(),
            'mail' => ! $this->delivered(),
            default => true,
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->from((string) config('mail.from.address'), $this->senderName)
            ->subject($this->subject)
            ->greeting(__('Hello :name,', ['name' => $this->greetingName]));

        if ($this->replyTo !== null) {
            $mail->replyTo($this->replyTo, $this->agency);
        }

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        if ($this->url !== null) {
            $mail->action($this->actionLabel, $this->url);
        }

        return $mail->salutation($this->agency);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'reminder.'.$this->type,
            'organization_id' => $this->organizationId,
            'reminder_id' => $this->reminderId,
            'organization' => $this->agency,
            'message' => $this->subject,
            'url' => $this->url,
        ];
    }

    /** Whether the reminder already went (its row says so). */
    private function delivered(): bool
    {
        $organization = Organization::query()->find($this->organizationId);

        return $organization !== null && app(OrganizationContext::class)->run($organization, fn (): bool => Reminder::query()
            ->whereKey($this->reminderId)->whereNotNull('delivered_at')->exists());
    }
}
