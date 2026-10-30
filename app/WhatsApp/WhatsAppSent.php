<?php

declare(strict_types=1);

namespace App\WhatsApp;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A WhatsApp message went (Meta took it, P05-06; or the log stand-in kept
 * it): what asked for it may record that it went (Reminders marks the
 * reminder delivered). `deliveryId` is its `notification_deliveries` row,
 * null for the log stand-in. Plain values.
 */
final readonly class WhatsAppSent
{
    use Dispatchable;

    public function __construct(
        public int $organizationId,
        public ?int $deliveryId,
        public ?string $sourceType,
        public ?int $sourceId,
    ) {}
}
