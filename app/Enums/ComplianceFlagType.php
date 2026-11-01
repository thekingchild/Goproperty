<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What the Transaction Tier monitoring noticed (P06-09,
 * ONBOARDING_COMPLIANCE.md § 5.4; `compliance_flags.type`). Who is told
 * follows § 5.4's table: some types go to the owners, some to the
 * compliance queue, some to both.
 */
enum ComplianceFlagType: string
{
    use HasValues;

    case Volume80 = 'volume_80';
    case ForecastOver = 'forecast_over';
    case Volume100 = 'volume_100';
    case SingleOver = 'single_over';
    case CashThreshold = 'cash_threshold';
    case PossibleStructuring = 'possible_structuring';
    case Spike = 'spike';
    case DocumentExpiring = 'document_expiring';
    case DocumentExpired = 'document_expired';

    public function label(): string
    {
        return match ($this) {
            self::Volume80 => 'Yearly volume at 80 % of the limit',
            self::ForecastOver => 'Forecast over the yearly limit',
            self::Volume100 => 'Yearly volume over the limit',
            self::SingleOver => 'Single payment over the limit',
            self::CashThreshold => 'Cash payment at the reporting threshold',
            self::PossibleStructuring => 'Possible structuring',
            self::Spike => 'Monthly volume spike',
            self::DocumentExpiring => 'Document expiring',
            self::DocumentExpired => 'Document expired',
        };
    }

    /** Flux / Filament colour. */
    public function color(): string
    {
        return match ($this) {
            self::Volume100, self::SingleOver, self::PossibleStructuring, self::DocumentExpired => 'danger',
            self::Volume80, self::ForecastOver, self::CashThreshold, self::Spike, self::DocumentExpiring => 'warning',
        };
    }

    /** The types the compliance queue opens on (§ 5.4 "compliance queue"). */
    public function forCompliance(): bool
    {
        return in_array($this, self::queue(), true);
    }

    /** @return list<self> */
    public static function queue(): array
    {
        return [self::Volume100, self::SingleOver, self::CashThreshold, self::PossibleStructuring, self::Spike];
    }

    /** The flags measured against the yearly limit, one episode at a time (default 89). */
    public function isVolume(): bool
    {
        return in_array($this, [self::Volume80, self::ForecastOver, self::Volume100], true);
    }
}
