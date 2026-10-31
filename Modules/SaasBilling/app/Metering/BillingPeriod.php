<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Metering;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * A subscription's billing period (P06-02; BILLING_PAYMENTS.md § 1.4, § 3;
 * docs/plans/P06-subtasks.md defaults 13–15, the first two the product
 * owner's choice of 2026-10-30), Lagos dates:
 *
 * - **Monthly:** calendar months, billed on the 1st. A period that starts
 *   after the 1st (the first after the trial, or after a reactivation) runs
 *   to the month's last day and is charged by days: `daysBilled` of
 *   `daysInMonth`.
 * - **Yearly:** 12 months from the day it starts (the trial's end), at
 *   `monthsBilled` months' price ("pay 10 months, get 12"), renewed on the
 *   same date each year.
 */
final readonly class BillingPeriod
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        /** Yearly: the months paid for (10); monthly: 1. */
        public int $monthsBilled = 1,
        /** A part month: the days charged, else null. */
        public ?int $daysBilled = null,
        public ?int $daysInMonth = null,
    ) {}

    /** The period that starts on $start for this cycle. */
    public static function starting(CarbonImmutable $start, BillingCycle $cycle, int $monthsPaidPerYear): self
    {
        $start = self::day($start);

        if ($cycle === BillingCycle::Annual) {
            return new self($start, $start->addYearNoOverflow()->subDay(), monthsBilled: $monthsPaidPerYear);
        }

        $end = $start->endOfMonth()->startOfDay();

        return $start->day === 1
            ? new self($start, $end)
            : new self($start, $end, daysBilled: $end->day - $start->day + 1, daysInMonth: $start->daysInMonth);
    }

    /**
     * The period to invoice for this subscription on $today, or null while
     * its current period runs (or it is not to be billed again):
     *
     * - no period yet (activated without a trial end): from today;
     * - the first period after the trial (`current_period_end` still empty):
     *   from `current_period_start`, the trial's last day;
     * - the current period is over: from the day after it, unless the
     *   subscription was not billed for a whole month (a year, for yearly) in
     *   between, as when it was suspended or cancelled: then from today, so
     *   a customer is never billed for months it was not on GoProperty;
     * - `cancel_at_period_end`: nothing after the current period.
     */
    public static function due(Subscription $subscription, CarbonImmutable $today, int $monthsPaidPerYear): ?self
    {
        $today = self::day($today);
        $cycle = $subscription->billing_cycle;

        if ($subscription->current_period_start === null) {
            return self::starting($today, $cycle, $monthsPaidPerYear);
        }

        if ($subscription->current_period_end === null) {
            return self::starting(self::day($subscription->current_period_start), $cycle, $monthsPaidPerYear);
        }

        $next = self::day($subscription->current_period_end)->addDay();

        if ($next->greaterThan($today) || $subscription->cancel_at_period_end) {
            return null;
        }

        $missedWhole = $cycle === BillingCycle::Annual
            ? $next->addYearNoOverflow()->lessThanOrEqualTo($today)
            : $next->lessThan($today->startOfMonth());

        return self::starting($missedWhole ? $today : $next, $cycle, $monthsPaidPerYear);
    }

    /**
     * The period the next metering run will bill for this subscription
     * (P06-06, for quotes), seen on $today, with the day it is billed:
     *
     * - in trial: the period that starts on the trial's last day, billed
     *   that day (the trial's end makes it active, then it is invoiced);
     * - a period already due: the one due() gives, billed in today's run;
     * - else the period after the current one, billed on its first day.
     *
     * A grace or suspended subscription is billed only once it is active
     * again; the period is then the one shown here, as long as it pays
     * within the month. With `cancel_at_period_end` (nothing sets it yet)
     * the period after the current one is still returned, though it will
     * not be billed.
     *
     * @return array{0: self, 1: CarbonImmutable} the period and its billing day
     */
    public static function next(Subscription $subscription, CarbonImmutable $today, int $monthsPaidPerYear): array
    {
        $today = self::day($today);
        $cycle = $subscription->billing_cycle;

        if ($subscription->status === SubscriptionStatus::Trialing) {
            $period = self::starting(self::day($subscription->trial_ends_at ?? $today), $cycle, $monthsPaidPerYear);
        } else {
            $after = $subscription->current_period_end !== null ? self::day($subscription->current_period_end)->addDay() : $today;
            $period = self::due($subscription, $today, $monthsPaidPerYear) ?? self::starting($after, $cycle, $monthsPaidPerYear);
        }

        return [$period, $period->start->max($today)];
    }

    public function isPartMonth(): bool
    {
        return $this->daysBilled !== null;
    }

    /** "16–30 November 2026", "16 November 2026 – 15 November 2027". */
    public function label(): string
    {
        return self::describe($this->start, $this->end);
    }

    public static function describe(CarbonImmutable $start, CarbonImmutable $end): string
    {
        if ($start->isSameMonth($end)) {
            return $start->format('j').'–'.$end->format('j F Y');
        }

        return $start->format('j F Y').' – '.$end->format('j F Y');
    }

    /** A Lagos calendar day at midnight, from a date or a moment. */
    private static function day(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->setTimezone('Africa/Lagos')->toDateString(), 'Africa/Lagos');
    }
}
