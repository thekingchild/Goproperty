<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\CycleCalculator;

require_once __DIR__.'/helpers.php';

/*
 * P03-02 AC: property-based tests over thousands of seeded anchors across
 * 1999–2101 (helpers.php) and every cycle. Each property is checked against
 * PHP's own calendar (the oracle in helpers.php), not against the
 * calculator's arithmetic. Mismatches are collected and reported together,
 * each naming the anchor, cycle and period, so one run shows every broken
 * case (and a per-check expect() would make the suite minutes slower).
 */

dataset('cycles', fn (): array => leasingCycleCases(BillingCycle::cases()));

dataset('month cycles', fn (): array => leasingCycleCases(
    array_filter(BillingCycle::cases(), fn (BillingCycle $cycle): bool => $cycle->months() !== null),
));

it('ends every period the day before the next starts: start + cycle − 1 day, short months clamped', function (BillingCycle $cycle) {
    $calculator = new CycleCalculator;
    $failures = [];
    $checked = 0;

    foreach (leasingSampleAnchors() as $anchor) {
        $anchorDate = CarbonImmutable::parse($anchor);

        for ($n = -2; $n <= leasingPeriodsToWalk($cycle); $n++) {
            $period = $calculator->period($anchorDate, $cycle, $n);
            $where = "{$cycle->value} from {$anchor}, period {$n}";

            leasingSame($failures, leasingYmd($period->start), leasingExpectedStart($anchor, $cycle, $n), $where.': start');
            leasingSame($failures, leasingYmd($period->end), leasingShift(leasingExpectedStart($anchor, $cycle, $n + 1), -1), $where.': end');
            leasingSame($failures, (string) $period->index, (string) $n, $where.': index');
            leasingSame($failures, (string) $period->lengthInDays, (string) leasingInclusiveDays($period->start, $period->end), $where.': length');

            // "start + cycle − 1 day", read literally where no month is short:
            // day cycles always, month cycles whenever the anchor day is ≤ 28.
            if ($cycle->days() !== null) {
                leasingSame($failures, (string) $period->lengthInDays, (string) $cycle->days(), $where.': day-cycle length');
            } elseif ((int) substr($anchor, 8, 2) <= 28) {
                $literal = leasingDay(leasingYmd($period->start))->modify(sprintf('+%d months -1 day', $cycle->months()));
                leasingSame($failures, leasingYmd($period->end), leasingYmd($literal), $where.': literal end');
            }

            $checked++;
        }
    }

    expect($failures)->toBe([])
        ->and($checked)->toBeGreaterThan(10_000);
})->with('cycles');

it('lays periods end to end: contiguous, non-empty and non-overlapping', function (BillingCycle $cycle) {
    $calculator = new CycleCalculator;
    $failures = [];

    foreach (leasingSampleAnchors() as $anchor) {
        $anchorDate = CarbonImmutable::parse($anchor);
        $previous = $calculator->period($anchorDate, $cycle, -1);

        for ($n = 0; $n <= leasingPeriodsToWalk($cycle); $n++) {
            $period = $calculator->period($anchorDate, $cycle, $n);
            $where = "{$cycle->value} from {$anchor}, period {$n}";

            leasingSame($failures, leasingYmd($period->start), leasingShift(leasingYmd($previous->end), 1), $where.': gap or overlap');

            if (leasingYmd($period->start) > leasingYmd($period->end)) {
                $failures[] = $where.': empty period';
            }

            $previous = $period;
        }
    }

    expect($failures)->toBe([]);
})->with('cycles');

it('reaches the same start by stepping period to period as by counting from the anchor (no drift)', function (BillingCycle $cycle) {
    $calculator = new CycleCalculator;
    $failures = [];

    foreach (leasingSampleAnchors() as $anchor) {
        $anchorDate = CarbonImmutable::parse($anchor);
        $stepped = $anchorDate;

        leasingSame($failures, leasingYmd($calculator->periodStart($anchorDate, $cycle, 0)), $anchor, "{$cycle->value} from {$anchor}, period 0");

        for ($n = 1; $n <= leasingPeriodsToWalk($cycle); $n++) {
            $stepped = $calculator->nextPeriodStart($anchorDate, $cycle, $stepped);

            leasingSame($failures, leasingYmd($stepped), leasingYmd($calculator->periodStart($anchorDate, $cycle, $n)), "{$cycle->value} from {$anchor}, period {$n}");
        }
    }

    expect($failures)->toBe([]);
})->with('cycles');

it('returns to the anchor day after a short month', function (BillingCycle $cycle) {
    $calculator = new CycleCalculator;
    $failures = [];
    $returns = 0;

    foreach (leasingSampleAnchors() as $anchor) {
        $anchorDay = (int) substr($anchor, 8, 2);
        $anchorDate = CarbonImmutable::parse($anchor);
        $clamped = false;

        for ($n = 0; $n <= leasingPeriodsToWalk($cycle); $n++) {
            $start = $calculator->periodStart($anchorDate, $cycle, $n);
            $where = "{$cycle->value} from {$anchor}, period {$n}";

            if ($start->daysInMonth >= $anchorDay) {
                leasingSame($failures, (string) $start->day, (string) $anchorDay, $where.': anchor day lost');
                $returns += $clamped ? 1 : 0;
                $clamped = false;
            } else {
                leasingSame($failures, (string) $start->day, (string) $start->daysInMonth, $where.': not clamped to the month end');
                $clamped = true;
            }
        }
    }

    // The samples really do clamp and come back (29 Feb anchors for yearly cycles).
    expect($failures)->toBe([])
        ->and($returns)->toBeGreaterThan(20);
})->with('month cycles');

it('finds the period containing any date, before or after the anchor', function (BillingCycle $cycle) {
    $calculator = new CycleCalculator;
    $random = leasingRandomizer(1);
    $failures = [];

    foreach (leasingSampleAnchors() as $anchor) {
        $anchorDate = CarbonImmutable::parse($anchor);
        $n = $random->getInt(-3, leasingPeriodsToWalk($cycle));
        $period = $calculator->period($anchorDate, $cycle, $n);
        $where = "{$cycle->value} from {$anchor}, period {$n}";

        foreach ([$period->start, $period->end] as $edge) {
            leasingSame($failures, (string) $calculator->periodContaining($anchorDate, $cycle, $edge)->index, (string) $n, $where.': '.leasingYmd($edge));
        }

        // Any day in the period, given with a time of day: still that period.
        $inside = leasingShift(leasingYmd($period->start), $random->getInt(0, $period->lengthInDays - 1));
        $containing = $calculator->periodContaining($anchorDate, $cycle, CarbonImmutable::parse($inside.' 23:59:59'));

        leasingSame($failures, (string) $containing->index, (string) $n, $where.': '.$inside);

        if (! $containing->contains(leasingDay($inside))) {
            $failures[] = $where.': does not contain '.$inside;
        }

        leasingSame(
            $failures,
            leasingYmd($calculator->nextPeriodStart($anchorDate, $cycle, leasingDay($inside))),
            leasingExpectedStart($anchor, $cycle, $n + 1),
            $where.': next start after '.$inside,
        );
    }

    expect($failures)->toBe([]);
})->with('cycles');

it('counts the periods between two dates', function (BillingCycle $cycle) {
    $calculator = new CycleCalculator;
    $random = leasingRandomizer(2);
    $failures = [];

    foreach (leasingSampleAnchors() as $anchor) {
        $anchorDate = CarbonImmutable::parse($anchor);
        $from = $random->getInt(-2, 4);
        $to = $from + $random->getInt(0, leasingPeriodsToWalk($cycle));
        $where = "{$cycle->value} from {$anchor}, periods {$from}..{$to}";

        $fromStart = $calculator->periodStart($anchorDate, $cycle, $from);
        $toPeriod = $calculator->period($anchorDate, $cycle, $to);

        leasingSame($failures, (string) $calculator->periodsBetween($anchorDate, $cycle, $fromStart, $toPeriod->start), (string) ($to - $from), $where);
        leasingSame($failures, (string) $calculator->periodsBetween($anchorDate, $cycle, $fromStart, $toPeriod->end), (string) ($to - $from), $where.' (to the last day)');
        leasingSame($failures, (string) $calculator->periodsBetween($anchorDate, $cycle, $fromStart, $fromStart), '0', $where.' (same day)');
    }

    expect($failures)->toBe([]);
})->with('cycles');

it('puts the due date $offset days after the period start and the bill date $lead days before it', function () {
    $calculator = new CycleCalculator;
    $random = leasingRandomizer(3);
    $failures = [];

    foreach (leasingSampleAnchors() as $anchor) {
        $offset = $random->getInt(0, 60);
        $lead = $random->getInt(0, 60);
        $start = CarbonImmutable::parse($anchor);

        leasingSame($failures, leasingYmd($calculator->dueDate($start, $offset)), leasingShift($anchor, $offset), "due {$offset} days after {$anchor}");
        leasingSame($failures, leasingYmd($calculator->billingDate($start, $lead)), leasingShift($anchor, -$lead), "billed {$lead} days before {$anchor}");
        leasingSame($failures, leasingYmd($calculator->dueDate($start)), $anchor, "due by default on {$anchor}");
        leasingSame($failures, leasingYmd($calculator->billingDate($start)), leasingShift($anchor, -7), "billed by default 7 days before {$anchor}");
    }

    expect($failures)->toBe([]);
});
