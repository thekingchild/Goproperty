<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

use App\Enums\Concerns\HasValues;

/**
 * How often a lease (or a utility) bills: `leases.billing_cycle`,
 * `utility_types.default_cycle`, `unit_utility_settings.cycle`
 * (DATABASE_SCHEMA.md § 3; ARCHITECTURE.md § 6.3). Public so Billing can
 * type its utility cycles with the same enum; the dates themselves come
 * from CycleCalculator only (AGENT.md § 3 rule 3).
 *
 * Daily and weekly cycles are plain day counts; the others are whole
 * calendar months anchored to the start date's day of the month.
 */
enum BillingCycle: string
{
    use HasValues;

    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Annual = 'annual';
    case Biennial = 'biennial';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::SemiAnnual => 'Every six months',
            self::Annual => 'Yearly',
            self::Biennial => 'Every two years',
        };
    }

    /** The period length in days for a day-count cycle, else null. */
    public function days(): ?int
    {
        return match ($this) {
            self::Daily => 1,
            self::Weekly => 7,
            default => null,
        };
    }

    /** The period length in calendar months for a month-based cycle, else null. */
    public function months(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::SemiAnnual => 6,
            self::Annual => 12,
            self::Biennial => 24,
            default => null,
        };
    }
}
