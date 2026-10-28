<?php

declare(strict_types=1);

namespace Modules\Handovers\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How a moving tenant's unused credit is settled at a management handover
 * (P03 decision 11). The organization whose books hold it chooses (the
 * landlord at a management; the agency at a one-way return, P04-04, where
 * `pass_to_agency` means passing it to the landlord: the value names the
 * receiving organization's side); until it is settled the credit stays on
 * those books, visible to both organizations.
 */
enum CreditSettlement: string
{
    use HasValues;

    /** The landlord pays it to the agency outside GoProperty; the agency records it once the money arrived. */
    case PassToAgency = 'pass_to_agency';

    /** The landlord pays it back to the tenant outside GoProperty and records the refund. */
    case Refund = 'refund';

    public function label(HandoverKind $kind = HandoverKind::Management): string
    {
        return match ($this) {
            self::PassToAgency => $kind === HandoverKind::Return ? 'Pass to the landlord' : 'Pass to the agency',
            self::Refund => 'Refund the tenant',
        };
    }
}
