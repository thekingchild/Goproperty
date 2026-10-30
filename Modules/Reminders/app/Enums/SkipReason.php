<?php

declare(strict_types=1);

namespace Modules\Reminders\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Why a planned reminder was not sent (docs/plans/P05-subtasks.md defaults
 * 6 and 7): checked again just before sending.
 */
enum SkipReason: string
{
    use HasValues;

    /** Its day ended before it could be sent (no catch-up, default 5). */
    case Missed = 'missed';

    /** The bill was paid in full, voided or removed. */
    case Settled = 'settled';

    /** The lease ended, was terminated, or its period is no longer billed. */
    case LeaseEnded = 'lease_ended';

    /** A renewal was drafted for the lease. */
    case RenewalDrafted = 'renewal_drafted';

    /** The tenant or member can no longer be reached on this channel. */
    case Unreachable = 'unreachable';

    /** The email address is on the suppression list: it bounced or complained (P05-04). */
    case Suppressed = 'suppressed';

    /** The person turned this channel off for reminders (Settings → Notifications, P05-02). */
    case OptedOut = 'opted_out';

    /** The organization was suspended or cancelled. */
    case OrganizationInactive = 'organization_inactive';
}
