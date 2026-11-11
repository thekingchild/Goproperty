<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Paystack;

/**
 * GoProperty's Paystack account refused to start a checkout (a 4xx with a
 * message, such as `"email" must be a valid email`). The message is
 * Paystack's own, kept in the failure note for staff; the payer is told in
 * plain words. A PaystackUnavailable, so every caller that only knows
 * "Paystack could not be used" still catches it.
 */
final class PaystackRefused extends PaystackUnavailable
{
    /** Paystack refused the payer's email address. */
    public function aboutEmail(): bool
    {
        return str_contains(strtolower($this->getMessage()), 'email');
    }
}
