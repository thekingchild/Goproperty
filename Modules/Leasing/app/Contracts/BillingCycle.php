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

    /**
     * A cycle as listed, or as agencies write it in a spreadsheet ("Annual",
     * "Per annum", "Bi-annual", "Every 3 months"…), for the import sheets
     * (Leases, P03-13a; Utilities, P03-13b). Null when it is none of them.
     */
    public static function fromText(string $text): ?self
    {
        $key = (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($text));

        foreach (self::cases() as $cycle) {
            if ($key === preg_replace('/[^a-z0-9]/', '', mb_strtolower($cycle->label())) || $key === str_replace('_', '', $cycle->value)) {
                return $cycle;
            }
        }

        return match ($key) {
            'day', 'perday', 'everyday' => self::Daily,
            'week', 'perweek', 'everyweek' => self::Weekly,
            'month', 'permonth', 'everymonth', 'monthly' => self::Monthly,
            'quarter', 'perquarter', 'every3months', 'everythreemonths', '3months', 'threemonths' => self::Quarterly,
            'semiannually', 'biannual', 'biannually', 'halfyearly', 'every6months', 'everysixmonths', '6months', 'sixmonths' => self::SemiAnnual,
            'year', 'yearly', 'annually', 'perannum', 'peryear', 'everyyear', 'pa', '1year', 'oneyear' => self::Annual,
            'biennially', 'every2years', 'everytwoyears', '2years', 'twoyears' => self::Biennial,
            default => null,
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
