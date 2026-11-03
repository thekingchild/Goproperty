<?php

declare(strict_types=1);

namespace Modules\Reports\Enums;

/**
 * How long a bill has been owed past its due date (the arrears aging
 * report, P08-01): days since the due date, the due day itself being day
 * 0. See docs/plans/P08-subtasks.md (P08-01 defaults).
 */
enum AgingBucket: string
{
    case Days0To30 = '0_30';
    case Days31To60 = '31_60';
    case Days61To90 = '61_90';
    case Over90 = '90_plus';

    public static function forDays(int $days): self
    {
        return match (true) {
            $days <= 30 => self::Days0To30,
            $days <= 60 => self::Days31To60,
            $days <= 90 => self::Days61To90,
            default => self::Over90,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Days0To30 => '0–30 days',
            self::Days31To60 => '31–60 days',
            self::Days61To90 => '61–90 days',
            self::Over90 => '90+ days',
        };
    }
}
