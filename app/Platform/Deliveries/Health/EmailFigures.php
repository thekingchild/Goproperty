<?php

declare(strict_types=1);

namespace App\Platform\Deliveries\Health;

/**
 * Email in one period (P05-05). Email sends are not in
 * `notification_deliveries` yet, so there is no sent, delivered or failure
 * count; what the provider reports through its bounce and complaint
 * webhook (P05-04) is: addresses suppressed after a permanent bounce or a
 * spam complaint (`email_suppressions`, by when they were suppressed) and
 * the webhook calls received (`webhook_log`).
 */
final readonly class EmailFigures
{
    public function __construct(
        public DeliveryWindow $window,
        public int $bounced,
        public int $complaints,
        public int $webhookCalls,
    ) {}
}
