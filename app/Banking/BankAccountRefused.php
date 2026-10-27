<?php

declare(strict_types=1);

namespace App\Banking;

use RuntimeException;

/** A bank account change that is not allowed; the message is for the person who tried. */
class BankAccountRefused extends RuntimeException
{
    /** @param  string  $field  the form field the message belongs to */
    public function __construct(string $message, public readonly string $field = 'accountNumber')
    {
        parent::__construct($message);
    }
}
