<?php

declare(strict_types=1);

namespace App\WhatsApp;

use RuntimeException;
use Throwable;

/**
 * The organization cannot send this WhatsApp message (not connected, the
 * driver is off, no active template for the event, the number opted out,
 * or Meta refused it: WhatsAppRefused). Never retried. `deliveryId` is the
 * `notification_deliveries` row marked failed, when one was written.
 */
class WhatsAppUnavailable extends RuntimeException
{
    public function __construct(string $message = '', public readonly ?int $deliveryId = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
