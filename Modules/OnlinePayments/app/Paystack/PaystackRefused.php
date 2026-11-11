<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Paystack;

use RuntimeException;

/** Paystack refused the request with the organization's key (invalid key, a field it does not accept). The message is Paystack's, safe to show the owner. */
final class PaystackRefused extends RuntimeException
{
    /** Paystack refused the payer's email address (e.g. `"email" must be a valid email`). */
    public function aboutEmail(): bool
    {
        return str_contains(strtolower($this->getMessage()), 'email');
    }
}
