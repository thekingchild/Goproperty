<?php

declare(strict_types=1);

namespace Modules\Reminders\Listeners;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use App\WhatsApp\WhatsAppSent;
use Modules\Reminders\Models\Reminder;

/**
 * Meta took a reminder's WhatsApp message (P05-06; Core's
 * App\WhatsApp\WhatsAppSent): the reminder records when it went
 * (`delivered_at`), as an SMS reminder does. What happened after
 * (delivered, read, failed) is on the delivery row.
 */
final readonly class MarkReminderWhatsAppSent
{
    public function __construct(private OrganizationContext $context) {}

    public function handle(WhatsAppSent $event): void
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
