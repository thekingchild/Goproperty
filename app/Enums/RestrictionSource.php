<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Who restricted an organization (P06-09, `organizations.restriction_source`;
 * ONBOARDING_COMPLIANCE.md § 5.5): the monitoring, when a `volume_100`
 * grace ended without an upgrade application (it lifts the restriction
 * itself), or a staff member (only staff lift it).
 */
enum RestrictionSource: string
{
    use HasValues;

    case TierMonitoring = 'tier_monitoring';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::TierMonitoring => 'Over the Transaction Tier limit after the grace period',
            self::Staff => 'Restricted by GoProperty compliance',
        };
    }
}
