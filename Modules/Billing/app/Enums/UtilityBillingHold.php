<?php

declare(strict_types=1);

namespace Modules\Billing\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Why a unit's utility is not being billed (`unit_utility_settings.
 * billing_hold`, P03-06b; ARCHITECTURE.md § 6.4 "How utility bills are
 * built"). Set by Bills\UtilityBillGenerator while a period waits, and
 * kept when the period is closed without a bill; cleared by the next bill.
 */
enum UtilityBillingHold: string
{
    use HasValues;

    /** No lease of the unit covers the period: nobody to bill (never the next tenant). */
    case NoTenant = 'no_tenant';

    /** A metered period ended without a meter reading: nothing is estimated. */
    case NoReading = 'no_reading';

    /** The list's badge. */
    public function label(): string
    {
        return match ($this) {
            self::NoTenant => 'Not billed: no tenant on a lease',
            self::NoReading => 'Waiting for a meter reading',
        };
    }
}
