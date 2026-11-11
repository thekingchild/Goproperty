<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A post or comment in a Building Group was reported (docs/plans/
 * P07-subtasks.md default 46, product owner 2026-11-11): to the group's
 * moderators, in the app and by email, linking to the group's page where
 * they hide, remove or dismiss. Plain strings, built by
 * Moderation\ReportNotifier; never the report's note or the item's text.
 * Queued on `notifications` after the commit; a retried in-app job adds
 * nothing twice (the row's id is the notification's).
 */
final class PostReported extends Notification implements ShouldQueue
{
    use Queueable;

    public const string EVENT = 'building_group.reported';

    public int $tries = 5;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $groupId,
        public readonly string $subject,
        public readonly string $line,
        public readonly string $url,
        public readonly string $agency,
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
        return $notifiable instanceof User && $notifiable->email !== null ? ['database', 'mail'] : ['database'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $channel !== 'database' || ! DatabaseNotification::query()->whereKey($this->id)->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->greeting(__('Hello :name,', ['name' => $notifiable instanceof User ? $notifiable->name : '']))
            ->line($this->line)
            ->line(__('Open the group to look at it: you can hide or remove it, or dismiss the report.'))
            ->action(__('Open the group'), $this->url)
            ->salutation($this->agency);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => self::EVENT,
            'organization_id' => $this->organizationId,
            'group_id' => $this->groupId,
            'organization' => $this->agency,
            'message' => $this->subject,
            'url' => $this->url,
        ];
    }
}
