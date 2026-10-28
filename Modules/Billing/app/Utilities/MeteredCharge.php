<?php

declare(strict_types=1);

namespace Modules\Billing\Utilities;

use App\Support\Money;
use OverflowException;
use RoundingMode;

/**
 * What a metered consumption costs (P03-06b; ARCHITECTURE.md § 6.4 "How
 * utility bills are built"): consumption × rate per unit of measure, in
 * kobo, through App\Support\Money (AGENT.md § 3 rule 2), never floats.
 *
 * The consumption is a MeterValue (whole thousandths), the rate a Money
 * per one unit of measure (`utility_readings.rate_kobo`), so the exact
 * amount is thousandths × rate ÷ 1,000. Worked as whole units × rate
 * (exact) plus the remaining thousandths × rate ÷ 1,000, rounded to the
 * nearest kobo, halves away from zero — the same as rounding the exact
 * product once, without an intermediate value larger than the answer.
 * An amount larger than Money holds fails loudly (OverflowException).
 */
final class MeteredCharge
{
    /** @throws OverflowException */
    public static function of(MeterValue $consumption, Money $rate): Money
    {
        $whole = intdiv($consumption->thousandths, MeterValue::SCALE);
        $fraction = $consumption->thousandths % MeterValue::SCALE;

        return $rate->times($whole)->plus($rate->times($fraction)->dividedBy(MeterValue::SCALE, RoundingMode::HalfAwayFromZero));
    }
}
