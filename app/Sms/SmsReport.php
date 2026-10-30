<?php

declare(strict_types=1);

namespace App\Sms;

/** A message's status when checked again: delivered, not delivered, or not known yet (null). */
final readonly class SmsReport
{
    public function __construct(
        public ?DeliveryStatus $status,
        public ?string $providerStatus,
    ) {}
}
