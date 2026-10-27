<?php

declare(strict_types=1);

namespace Modules\Payments\Receipts;

use Illuminate\Validation\ValidationException;

/**
 * A receipt void step that cannot happen (P03-10b): the receipt is voided
 * already, a request waits or none does, the requester tries to approve
 * their own request. Nothing was changed. A ValidationException (key
 * `receipt`), so the page shows the message where the action is.
 */
final class ReceiptVoidRefused extends ValidationException
{
    public static function because(string $message): self
    {
        return self::withMessages(['receipt' => $message]);
    }
}
