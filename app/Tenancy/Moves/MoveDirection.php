<?php

declare(strict_types=1);

namespace App\Tenancy\Moves;

/**
 * Which way a management handover moves buildings (BILLING_PAYMENTS.md
 * § 7): to the managing organization when it accepts, back to the
 * landlord's organization when management ends, or (§ 7.1, P04-04) from
 * an agency that held them without a handover into the landlord's own
 * organization, for good.
 */
enum MoveDirection: string
{
    /** Accepted: from the landlord's organization to the agency's. */
    case Handover = 'handover';

    /** Ended: from the agency's organization back to the landlord's. */
    case Return = 'return';

    /**
     * A one-way return (§ 7.1, § 7.2 `returned`): an agency hands buildings
     * it held without a handover (added or imported by it) to the
     * landlord's own organization. Nothing moves back later.
     */
    case OneWayReturn = 'one_way_return';
}
