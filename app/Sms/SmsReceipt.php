<?php

declare(strict_types=1);

namespace App\Sms;

/** What the provider said when it took a message (GoText's `uid`, status word, pages and cost when it gives them). */
final readonly class SmsReceipt
{
    public function __construct(
        public ?string $messageId,
        public ?string $providerStatus = null,
        public ?int $pages = null,
        public ?int $costKobo = null,
    ) {}
}
