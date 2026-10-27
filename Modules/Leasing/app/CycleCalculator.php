<?php

declare(strict_types=1);

namespace Modules\Leasing;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Contracts\BillingPeriod;
use Modules\Leasing\Contracts\CycleCalculator as CycleCalculatorContract;

/**
 * AGENT.md § 3 rule 3: all lease period arithmetic happens here, and only
 * here (CycleMathBoundaryTest bans Carbon interval maths elsewhere in
 * Leasing and Billing). The rules are on the contract; ARCHITECTURE.md § 6.3
 * explains them.
 *
 * Everything is integer arithmetic on calendar days (days since 1970-01-01
 * in UTC) and on month numbers (year × 12 + month), turned into dates only at
 * the end. No Carbon add/sub: month overflow, DST and the time of day cannot
 * leak into a period.
 */
final class CycleCalculator implements CycleCalculatorContract
{
    private const int SECONDS_PER_DAY = 86400;

    public function periodStart(DateTimeInterface $anchor, BillingCycle $cycle, int $index): CarbonImmutable
    {
        return self::toDate(self::startOf(self::parts($anchor), $cycle, $index), self::midnight($anchor));
    }

    public function period(DateTimeInterface $anchor, BillingCycle $cycle, int $index): BillingPeriod
    {
        return self::periodOf(self::parts($anchor), $cycle, $index, self::midnight($anchor));
    }

    public function periodContaining(DateTimeInterface $anchor, BillingCycle $cycle, DateTimeInterface $date): BillingPeriod
    {
        $parts = self::parts($anchor);

        return self::periodOf($parts, $cycle, self::indexOf($parts, $cycle, self::parts($date)), self::midnight($anchor));
    }

    public function nextPeriodStart(DateTimeInterface $anchor, BillingCycle $cycle, DateTimeInterface $date): CarbonImmutable
    {
        $parts = self::parts($anchor);
        $index = self::indexOf($parts, $cycle, self::parts($date)) + 1;

        return self::toDate(self::startOf($parts, $cycle, $index), self::midnight($anchor));
    }

    public function periodsBetween(DateTimeInterface $anchor, BillingCycle $cycle, DateTimeInterface $from, DateTimeInterface $to): int
    {
        [$anchorParts, $fromParts, $toParts] = [self::parts($anchor), self::parts($from), self::parts($to)];

        if (self::dayNumber($toParts) < self::dayNumber($fromParts)) {
            throw new InvalidArgumentException('The end date is before the start date.');
        }

        return self::indexOf($anchorParts, $cycle, $toParts) - self::indexOf($anchorParts, $cycle, $fromParts);
    }

    public function dueDate(DateTimeInterface $periodStart, int $dueDayOffset = 0): CarbonImmutable
    {
        if ($dueDayOffset < 0) {
            throw new InvalidArgumentException('The due-day offset cannot be negative: a bill falls due on or after its period start.');
        }

        return self::toDate(self::fromDayNumber(self::dayNumber(self::parts($periodStart)) + $dueDayOffset), self::midnight($periodStart));
    }

    public function daysAfter(DateTimeInterface $date, int $days): CarbonImmutable
    {
        return self::toDate(self::fromDayNumber(self::dayNumber(self::parts($date)) + $days), self::midnight($date));
    }

    public function billingDate(DateTimeInterface $periodStart, int $leadDays = self::DEFAULT_LEAD_DAYS): CarbonImmutable
    {
        if ($leadDays < 0) {
            throw new InvalidArgumentException('The lead window cannot be negative: a bill is generated on or before its period start.');
        }

        return self::toDate(self::fromDayNumber(self::dayNumber(self::parts($periodStart)) - $leadDays), self::midnight($periodStart));
    }

    /**
     * @param  array{int, int, int}  $anchor
     */
    private static function periodOf(array $anchor, BillingCycle $cycle, int $index, CarbonImmutable $midnight): BillingPeriod
    {
        $start = self::startOf($anchor, $cycle, $index);
        $end = self::dayNumber(self::startOf($anchor, $cycle, $index + 1)) - 1;

        return new BillingPeriod(
            index: $index,
            start: self::toDate($start, $midnight),
            end: self::toDate(self::fromDayNumber($end), $midnight),
            lengthInDays: $end - self::dayNumber($start) + 1,
        );
    }

    /**
     * Period $index's first day as [year, month, day].
     *
     * @param  array{int, int, int}  $anchor
     * @return array{int, int, int}
     */
    private static function startOf(array $anchor, BillingCycle $cycle, int $index): array
    {
        $days = $cycle->days();

        if ($days !== null) {
            return self::fromDayNumber(self::dayNumber($anchor) + $index * $days);
        }

        $month = self::monthNumber($anchor) + $index * self::monthsOf($cycle);
        $year = self::floorDiv($month, 12);
        $monthOfYear = $month - $year * 12 + 1;

        // Month-end anchoring: clamp to a short month's last day; the anchor
        // day comes back in the next month long enough for it.
        return [$year, $monthOfYear, min($anchor[2], self::daysInMonth($year, $monthOfYear))];
    }

    /**
     * The number of the period the date falls in.
     *
     * @param  array{int, int, int}  $anchor
     * @param  array{int, int, int}  $date
     */
    private static function indexOf(array $anchor, BillingCycle $cycle, array $date): int
    {
        $days = $cycle->days();

        if ($days !== null) {
            return self::floorDiv(self::dayNumber($date) - self::dayNumber($anchor), $days);
        }

        // A first guess from whole months: period $index starts in the date's
        // month or an earlier one, so at most one step back is needed (when
        // it starts later in the date's own month).
        $index = self::floorDiv(self::monthNumber($date) - self::monthNumber($anchor), self::monthsOf($cycle));

        if (self::dayNumber(self::startOf($anchor, $cycle, $index)) > self::dayNumber($date)) {
            $index--;
        }

        return $index;
    }

    private static function monthsOf(BillingCycle $cycle): int
    {
        return $cycle->months() ?? throw new InvalidArgumentException("The {$cycle->value} cycle is not month-based.");
    }

    /**
     * The date's calendar day as [year, month, day]; its time is ignored.
     *
     * @return array{int, int, int}
     */
    private static function parts(DateTimeInterface $date): array
    {
        [$year, $month, $day] = explode('-', $date->format('Y-n-j'));

        return [(int) $year, (int) $month, (int) $day];
    }

    /**
     * Days since 1970-01-01.
     *
     * @param  array{int, int, int}  $day
     */
    private static function dayNumber(array $day): int
    {
        return intdiv((int) gmmktime(0, 0, 0, $day[1], $day[2], $day[0]), self::SECONDS_PER_DAY);
    }

    /** @return array{int, int, int} */
    private static function fromDayNumber(int $dayNumber): array
    {
        [$year, $month, $day] = explode('-', gmdate('Y-n-j', $dayNumber * self::SECONDS_PER_DAY));

        return [(int) $year, (int) $month, (int) $day];
    }

    /**
     * Months since year 0 (year × 12 + month − 1).
     *
     * @param  array{int, int, int}  $day
     */
    private static function monthNumber(array $day): int
    {
        return $day[0] * 12 + $day[1] - 1;
    }

    /** Midnight of the date's day in its time zone: the base every returned date is set from. */
    private static function midnight(DateTimeInterface $date): CarbonImmutable
    {
        return ($date instanceof CarbonImmutable ? $date : CarbonImmutable::instance($date))->setTime(0, 0);
    }

    /**
     * @param  array{int, int, int}  $day
     */
    private static function toDate(array $day, CarbonImmutable $midnight): CarbonImmutable
    {
        return $midnight->setDate($day[0], $day[1], $day[2]);
    }

    private static function daysInMonth(int $year, int $month): int
    {
        return match ($month) {
            2 => self::isLeapYear($year) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }

    private static function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    private static function floorDiv(int $dividend, int $divisor): int
    {
        $quotient = intdiv($dividend, $divisor);

        return ($dividend % $divisor !== 0 && ($dividend < 0) !== ($divisor < 0)) ? $quotient - 1 : $quotient;
    }
}
