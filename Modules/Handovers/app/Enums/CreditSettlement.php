<?php

declare(strict_types=1);

namespace Modules\Handovers\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How a moving tenant's unused credit is settled at a management handover
 * (P03 decision 11). The landlord chooses; until it is settled the credit
 * stays on the landlord's books, visible to both organizations.
 */
enum CreditSettlement: string
{
    use HasValues;

    /** The landlord pays it to the agency outside GoProperty; the agency records it once the money arrived. */
    case PassToAgency = 'pass_to_agency';

    /** The landlord pays it back to the tenant outside GoProperty and records the refund. */
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::PassToAgency => 'Pass to the agency',
            self::Refund => 'Refund the tenant',
        };
    }
}
