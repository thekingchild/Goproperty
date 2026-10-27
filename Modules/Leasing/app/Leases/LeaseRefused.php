<?php

declare(strict_types=1);

namespace Modules\Leasing\Leases;

use Illuminate\Validation\ValidationException;

/**
 * A lease step that cannot happen: a move the state machine does not allow
 * (LeaseStatus), a unit already let, a deposit refund beyond what is held.
 * Nothing was changed. A ValidationException (key `lease` unless said
 * otherwise), so the pages show the message where the action is.
 */
final class LeaseRefused extends ValidationException
{
    public static function because(string $message, string $key = 'lease'): self
    {
        return self::withMessages([$key => $message]);
    }
}
