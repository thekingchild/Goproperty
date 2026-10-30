<?php

declare(strict_types=1);

namespace Modules\Reminders\Notifications;

use App\Mail\Templates\BrandedMail;
use App\Mail\Templates\EmailBrand;
use App\Mail\Templates\RenderedEmail;
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
 * notification, as the reminder row says: by email, or in the app (the
 * subject). The words are GoProperty's email template for it, filled in
 * by Reminders\ReminderSender (P05-04); the email is in the agency's name
 * and branding (EmailBrand: sender "Adeyemi Realty via GoProperty" or the
 * agency's own, replies to the agency's email; BrandedMail's layout).
 * Queued on `notifications` after the commit; a retried job sends nothing
 * twice: the in-app row's id is the reminder's `notification_id`, and the
 * email is skipped once the reminder says it went (`delivered_at`,
 * Listeners\MarkReminderDelivered). A suppressed address gets nothing
 * (checked before sending and again by Core's DropSuppressedRecipients).
 */
final class ReminderNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $reminderId,
        public readonly string $type,
        public readonly int $organizationId,
        public readonly RenderedEmail $email,
        public readonly EmailBrand $brand,
        public readonly ?string $url,
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
        return app(BrandedMail::class)->message($this->email, $this->brand, $this->url);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'reminder.'.$this->type,
            'organization_id' => $this->organizationId,
            'reminder_id' => $this->reminderId,
            'organization' => $this->brand->name,
            'message' => $this->email->subject,
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
