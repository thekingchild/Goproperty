<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Meta;

use RuntimeException;

/** Meta refused the request (4xx). `tokenRefused`: the access token itself was refused (revoked or expired). Never holds the token. */
final class MetaRefused extends RuntimeException
{
    public function __construct(string $message, public readonly bool $tokenRefused = false)
    {
        parent::__construct($message);
    }
}
