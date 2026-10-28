<?php

declare(strict_types=1);

namespace Modules\Tickets\Listeners;

use Illuminate\Notifications\Events\NotificationSent;
use Modules\Tickets\Notices\TicketNotifier;
use Modules\Tickets\Notifications\TicketUpdate;

/**
 * A ticket notice's email went (P04-03): its `ticket_notices` row says so
 * (`mailed_at`), so a retried mail job does not send it again
 * (Notifications\TicketUpdate::shouldSend).
 */
final readonly class MarkTicketNoticeMailed
{
    public function __construct(private TicketNotifier $notifier) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'mail' || ! $event->notification instanceof TicketUpdate) {
            return;
        }

        $this->notifier->markMailed($event->notification->organizationId, (string) $event->notification->id);
    }
}
