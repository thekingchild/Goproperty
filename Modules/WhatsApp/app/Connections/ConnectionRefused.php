<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Connections;

use RuntimeException;

/** A WhatsApp settings change that was refused, with the form field it belongs to and a message for the person. */
final class ConnectionRefused extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
