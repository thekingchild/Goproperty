<?php

declare(strict_types=1);

namespace Modules\Reminders\Listeners;

use App\Models\Organization;
use App\Sms\SmsSent;
use App\Tenancy\OrganizationContext;
use Modules\Reminders\Models\Reminder;

/**
 * GoText took a reminder's SMS (P05-02; Core's App\Sms\SmsSent): the
 * reminder records when it went (`delivered_at`), as an email or in-app
 * reminder does. What happened after (delivered or not) is on the
 * delivery row.
 */
final readonly class MarkReminderSmsSent
{
    public function __construct(private OrganizationContext $context) {}

    public function handle(SmsSent $event): void
    {
        if ($event->sourceType !== Reminder::SMS_SOURCE || $event->sourceId === null) {
            return;
        }

        $organization = Organization::query()->find($event->organizationId);

        if ($organization === null) {
            return;
        }

        $this->context->run($organization, static function () use ($event): void {
            $reminder = Reminder::query()->whereKey($event->sourceId)->whereNull('delivered_at')->first();

            if ($reminder instanceof Reminder) {
                $reminder->delivered_at = now()->toImmutable();
                $reminder->save();
            }
        });
    }
}
