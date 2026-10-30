<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Meta;

/** What Meta said when the connection was checked; the number's display form and verified name when it accepted. */
final readonly class ConnectionCheck
{
    public function __construct(
        public CheckResult $result,
        public ?string $displayPhoneNumber = null,
        public ?string $verifiedName = null,
        public ?string $error = null,
    ) {}
}
