<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use Illuminate\Validation\ValidationException;

/**
 * A change the bill's state does not allow (voiding a void bill, or one
 * with payments applied): shown on the page under the key `bill`, and
 * nothing changes.
 */
final class BillRefused extends ValidationException
{
    public static function because(string $message): self
    {
        return self::withMessages(['bill' => $message]);
    }
}
