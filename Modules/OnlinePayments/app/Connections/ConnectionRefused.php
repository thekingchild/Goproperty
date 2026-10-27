<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Connections;

use RuntimeException;

/** A change to the Paystack connection that was refused, with the form field it is about and a message for the owner. */
final class ConnectionRefused extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
