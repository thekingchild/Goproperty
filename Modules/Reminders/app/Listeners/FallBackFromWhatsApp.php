<?php

declare(strict_types=1);

namespace Modules\Reminders\Listeners;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use App\WhatsApp\WhatsAppFailed;
use Carbon\CarbonImmutable;
use Modules\Reminders\Enums\ReminderChannel;
use Modules\Reminders\Enums\ReminderStatus;
use Modules\Reminders\Models\Reminder;
use Modules\Reminders\Reminders\ReminderClock;
use Modules\Reminders\Reminders\ReminderPlanner;
use Modules\Reminders\Reminders\ReminderSender;

/**
 * A reminder's WhatsApp message did not reach the tenant (P05-06; Core's
 * App\WhatsApp\WhatsAppFailed: Meta refused it, it could not be sent, or
 * Meta took it and later reported it failed): the event's next channel
 * (SMS on an SMS rung, to a verified number) is planned and sent at once,
 * once per rung (ARCHITECTURE.md § 6.7: "that event falls back to SMS").
 * Only on the reminder's own Lagos day — no catch-up, as for every
 * reminder; a failure reported later changes nothing. The WhatsApp
 * reminder stays `sent` (it was handed over); its delivery row says what
 * became of it.
 */
final readonly class FallBackFromWhatsApp
{
    public function __construct(
        private OrganizationContext $context,
        private ReminderPlanner $planner,
        private ReminderSender $sender,
    ) {}

    public function handle(WhatsAppFailed $event): void
    {
        if ($event->sourceType !== Reminder::SMS_SOURCE || $event->sourceId === null) {
            return;
        }

        $organization = Organization::query()->find($event->organizationId);

        if ($organization === null) {
            return;
        }

        $this->context->run($organization, function () use ($event): void {
            $reminder = Reminder::query()->whereKey($event->sourceId)->first();

            if (! $reminder instanceof Reminder || $reminder->channel !== ReminderChannel::WhatsApp || $reminder->status !== ReminderStatus::Sent
                || $reminder->send_on->toDateString() !== ReminderClock::today()->toDateString()) {
                return;
            }

            $next = $this->planner->fallBack($reminder, CarbonImmutable::now());

            if ($next !== null) {
                $this->sender->sendPlanned($next->id);
            }
        });
    }
}
