<?php

declare(strict_types=1);

namespace App\Mail\Suppression;

/** One address a provider reported as undeliverable or complaining. */
final readonly class ProviderEvent
{
    public function __construct(
        public string $email,
        public SuppressionReason $reason,
        public ?string $detail,
    ) {}
}
