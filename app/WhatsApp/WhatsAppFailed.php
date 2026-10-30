<?php

declare(strict_types=1);

namespace App\WhatsApp;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A WhatsApp message did not reach the tenant (P05-06): it could not be
 * sent (no working connection, no active template, the number opted out),
 * Meta refused it, every retry failed, or Meta took it and later reported
 * it `failed` (status webhook). Sent once per message. What asked for it
 * may use its next channel (Reminders falls back to SMS,
 * ARCHITECTURE.md § 6.7). `deliveryId` is null when no delivery row was
 * written (refused before sending). Plain values; `reason` never holds a
 * token or the text.
 */
final readonly class WhatsAppFailed
{
    use Dispatchable;

    public function __construct(
        public int $organizationId,
        public ?int $deliveryId,
        public ?string $sourceType,
        public ?int $sourceId,
        public string $reason,
    ) {}
}
