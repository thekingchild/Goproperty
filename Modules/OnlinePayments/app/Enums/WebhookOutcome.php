<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Enums;

/** What processing a webhook delivery did (`webhook_log.outcome`). */
enum WebhookOutcome: string
{
    /** The signature did not match the organization's secret key: never processed. */
    case Rejected = 'rejected';

    /** Verified with Paystack and recorded as a confirmed payment. */
    case Confirmed = 'confirmed';

    /** Verified, but not as asked: recorded for review (or kept aside), not confirmed. */
    case Held = 'held';

    /** The transaction was settled before (a replay, or the return from Paystack got there first). */
    case Duplicate = 'duplicate';

    /** Paystack does not say the charge succeeded (yet): nothing recorded. */
    case NotPaid = 'not_paid';

    /** Not a payment GoProperty started, or an event it does not act on. */
    case Ignored = 'ignored';
}
