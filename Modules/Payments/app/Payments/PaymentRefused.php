<?php

declare(strict_types=1);

namespace Modules\Payments\Payments;

use Illuminate\Validation\ValidationException;

/**
 * A payment step that cannot happen: confirming or rejecting a payment
 * that is no longer waiting, an explicit allocation that does not fit its
 * bill. Nothing was changed. A ValidationException (key `payment` unless
 * said otherwise), so the pages show the message where the action is.
 */
final class PaymentRefused extends ValidationException
{
    public static function because(string $message, string $key = 'payment'): self
    {
        return self::withMessages([$key => $message]);
    }
}
