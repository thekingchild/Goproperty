<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The only place lease and bill period dates are worked out (AGENT.md § 3
 * rule 3, ARCHITECTURE.md § 6.3). Other modules (Billing, Reminders, Import)
 * type-hint this contract; Modules\Leasing\CycleCalculator implements it and
 * LeasingServiceProvider binds it. Pure: no clock, no database, no state.
 *
 * Rules (decision 1 of P03, ARCHITECTURE.md § 6.3):
 * - Periods are counted from an anchor, the lease start. Period 0 starts on
 *   the anchor; period n's start is computed from the anchor, never by
 *   stepping from the previous period, so nothing drifts.
 * - Daily and weekly cycles are plain counts of 1 or 7 days.
 * - Month-based cycles start on the anchor's day of the month. A month too
 *   short for that day starts on its last day, and the next period goes back
 *   to the anchor day: 31 Jan → 29 Feb 2028 → 31 Mar 2028. A 29 Feb anchor
 *   bills yearly on 28 Feb in common years and 29 Feb in leap years.
 * - A period ends the day before the next one starts.
 * - A bill is generated `lead_days` before its period starts and is due
 *   `due_day_offset` days after it starts (0 = on the start day).
 *
 * Arguments are calendar days: an argument's time of day is ignored, and
 * every date returned is midnight in the anchor's (or the given period
 * start's) time zone.
 */
interface CycleCalculator
{
    /** ARCHITECTURE.md § 6.4: bills are generated this many days ahead unless the organization says otherwise. */
    public const int DEFAULT_LEAD_DAYS = 7;

    /** The first day of period number $index (0 = the anchor itself). */
    public function periodStart(DateTimeInterface $anchor, BillingCycle $cycle, int $index): CarbonImmutable;

    /** Period number $index: its first and last day. */
    public function period(DateTimeInterface $anchor, BillingCycle $cycle, int $index): BillingPeriod;

    /** The period the date falls in (also before the anchor: negative index). */
    public function periodContaining(DateTimeInterface $anchor, BillingCycle $cycle, DateTimeInterface $date): BillingPeriod;

    /**
     * The first period start after the date. Given a period's start, this is
     * the next period's start (what `leases.next_billing_date` advances to).
     */
    public function nextPeriodStart(DateTimeInterface $anchor, BillingCycle $cycle, DateTimeInterface $date): CarbonImmutable;

    /**
     * How many period starts fall after $from and on or before $to. For two
     * period starts this is the number of whole periods between them.
     *
     * @throws InvalidArgumentException when $to is before $from
     */
    public function periodsBetween(DateTimeInterface $anchor, BillingCycle $cycle, DateTimeInterface $from, DateTimeInterface $to): int;

    /**
     * When a period's bill is due: $dueDayOffset days after the period
     * starts (`leases.due_day_offset`, default 0).
     *
     * @throws InvalidArgumentException when the offset is negative
     */
    public function dueDate(DateTimeInterface $periodStart, int $dueDayOffset = 0): CarbonImmutable;

    /**
     * When a period's bill is generated: $leadDays before the period starts
     * (the organization's lead window, default DEFAULT_LEAD_DAYS).
     *
     * @throws InvalidArgumentException when the lead is negative
     */
    public function billingDate(DateTimeInterface $periodStart, int $leadDays = self::DEFAULT_LEAD_DAYS): CarbonImmutable;
}
