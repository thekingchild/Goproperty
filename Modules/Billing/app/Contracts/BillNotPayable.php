<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use RuntimeException;

/**
 * The bill ledger refused to apply or release money (Contracts\BillLedger):
 * the bill is void or unknown, or the amount is not positive, more than
 * the bill still owes (apply) or more than was paid (release). Nothing
 * was changed. `reason` is one of the constants, for callers that react to
 * one case (e.g. put the rest of a payment to tenant credit).
 */
final class BillNotPayable extends RuntimeException
{
    public const string VOID = 'void';

    public const string UNKNOWN = 'unknown';

    public const string NOT_POSITIVE = 'not_positive';

    public const string TOO_MUCH = 'too_much';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
