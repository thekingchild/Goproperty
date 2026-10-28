<?php

declare(strict_types=1);

namespace App\Tenancy\Moves;

/**
 * Which way a management handover moves buildings (BILLING_PAYMENTS.md
 * § 7): to the managing organization when it accepts, or back to the
 * landlord's organization when management ends.
 */
enum MoveDirection: string
{
    /** Accepted: from the landlord's organization to the agency's. */
    case Handover = 'handover';

    /** Ended: from the agency's organization back to the landlord's. */
    case Return = 'return';
}
