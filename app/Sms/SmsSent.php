<?php

declare(strict_types=1);

namespace App\Sms;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * GoText took a message (P05-02): what asked for it may record that it
 * went (Reminders marks the reminder delivered). Plain values.
 */
final readonly class SmsSent
{
    use Dispatchable;

    public function __construct(
        public int $organizationId,
        public int $deliveryId,
        public ?string $sourceType,
        public ?int $sourceId,
    ) {}
}
