<?php

declare(strict_types=1);

namespace Modules\Reminders\Listeners;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Notifications\Events\NotificationSent;
use Modules\Reminders\Models\Reminder;
use Modules\Reminders\Notifications\ReminderNotice;

/**
 * A reminder's email went, or its in-app row was written (P05-01): the
 * reminder says so (`delivered_at`), so a retried job sends nothing again
 * (Notifications\ReminderNotice::shouldSend).
 */
final readonly class MarkReminderDelivered
{
    public function __construct(private OrganizationContext $context) {}

    public function handle(NotificationSent $event): void
    {
        if (! $event->notification instanceof ReminderNotice) {
            return;
        }

        $notice = $event->notification;
        $organization = Organization::query()->find($notice->organizationId);

        if ($organization === null) {
            return;
        }

        $this->context->run($organization, static function () use ($notice): void {
            $reminder = Reminder::query()->whereKey($notice->reminderId)->whereNull('delivered_at')->first();

            if ($reminder instanceof Reminder) {
                $reminder->delivered_at = now()->toImmutable();
                $reminder->save();
            }
        });
    }
}
