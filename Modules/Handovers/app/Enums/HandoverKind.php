<?php

declare(strict_types=1);

namespace Modules\Handovers\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What a management handover is (`management_handovers.kind`,
 * BILLING_PAYMENTS.md § 7.1).
 */
enum HandoverKind: string
{
    use HasValues;

    /** A landlord's organization hands buildings to an agency to manage; it ends with notice and the buildings come back. */
    case Management = 'management';

    /**
     * An agency hands buildings it held without a handover (added or
     * imported by it) to the landlord's own organization (P04-04). One-way:
     * accepting ends it as `returned`, with no notice and no end.
     */
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Management => 'Management',
            self::Return => 'Return to the landlord',
        };
    }

    /** The state an accepted offer of this kind ends in (§ 7.2). */
    public function acceptedStatus(): HandoverStatus
    {
        return match ($this) {
            self::Management => HandoverStatus::Active,
            self::Return => HandoverStatus::Returned,
        };
    }
}
