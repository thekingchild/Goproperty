<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Enums;

/** What settling a Paystack reference did (kept as a webhook delivery's `outcome`). */
enum PaymentOutcome: string
{
    case Confirmed = 'confirmed';
    case Held = 'held';
    case Duplicate = 'duplicate';
    case Ignored = 'ignored';
    case NotPaid = 'not_paid';
    case Rejected = 'rejected';
    // A refund event moved one of the payment's refunds (P06-07b).
    case Refund = 'refund';
}
