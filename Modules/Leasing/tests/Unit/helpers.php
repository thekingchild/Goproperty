<?php

declare(strict_types=1);

use Modules\Leasing\Contracts\BillingCycle;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/*
 * Samples and an independent oracle for the CycleCalculator property tests.
 * The oracle uses PHP's own DateTimeImmutable (in UTC, always from the 1st
 * of a month so `+N months` cannot overflow) rather than the calculator's
 * integer arithmetic, so the two can disagree when one is wrong.
 */

/** The fixed seed: a failure reproduces on every run and every machine. */
const LEASING_CYCLE_SEED = 20261027;

/**
 * Pest dataset rows named by cycle value.
 *
 * @param  array<BillingCycle>  $cycles
 * @return array<string, array{BillingCycle}>
 */
function leasingCycleCases(array $cycles): array
{
    $cases = [];

    foreach ($cycles as $cycle) {
        $cases[$cycle->value] = [$cycle];
    }

    return $cases;
}

/** How many periods each anchor is walked through, per cycle (enough to cross several leap years). */
function leasingPeriodsToWalk(BillingCycle $cycle): int
{
    return match ($cycle) {
        BillingCycle::Daily => 35,
        BillingCycle::Weekly => 15,
        BillingCycle::Monthly => 30,
        BillingCycle::Quarterly => 12,
        BillingCycle::SemiAnnual => 8,
        BillingCycle::Annual => 9,
        BillingCycle::Biennial => 5,
    };
}

/**
 * Anchors (Y-m-d) across 1999–2101: thousands of seeded random days plus
 * every edge the rules care about — every 29 Feb, the 28th to 31st of every
 * month in common, leap and century years (2000 is a leap year, 2100 is
 * not), and every year end and new year.
 *
 * @return list<string>
 */
function leasingSampleAnchors(): array
{
    static $anchors = null;

    if ($anchors !== null) {
        return $anchors;
    }

    $edges = [];

    for ($year = 1999; $year <= 2101; $year++) {
        $edges[] = sprintf('%04d-12-31', $year);
        $edges[] = sprintf('%04d-01-01', $year);

        if (checkdate(2, 29, $year)) {
            $edges[] = sprintf('%04d-02-29', $year);
        }
    }

    foreach ([1999, 2000, 2001, 2027, 2028, 2096, 2099, 2100, 2101] as $year) {
        for ($month = 1; $month <= 12; $month++) {
            foreach ([1, 28, 29, 30, 31] as $day) {
                if (checkdate($month, $day, $year)) {
                    $edges[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
                }
            }
        }
    }

    $randomizer = new Randomizer(new Xoshiro256StarStar(LEASING_CYCLE_SEED));
    $first = intdiv((int) gmmktime(0, 0, 0, 1, 1, 1999), 86400);
    $last = intdiv((int) gmmktime(0, 0, 0, 12, 31, 2101), 86400);
    $random = [];

    for ($i = 0; $i < 2000; $i++) {
        $random[] = gmdate('Y-m-d', $randomizer->getInt($first, $last) * 86400);
    }

    return $anchors = array_values(array_unique([...$edges, ...$random]));
}

/** A seeded random generator for picking dates and offsets inside a test. */
function leasingRandomizer(int $salt = 0): Randomizer
{
    return new Randomizer(new Xoshiro256StarStar(LEASING_CYCLE_SEED + $salt));
}

function leasingDay(string $ymd): DateTimeImmutable
{
    return new DateTimeImmutable($ymd.' 00:00:00', new DateTimeZone('UTC'));
}

function leasingYmd(DateTimeInterface $date): string
{
    return $date->format('Y-m-d');
}

/** The day $days after (or before) the given day, by PHP's own calendar. */
function leasingShift(string $ymd, int $days): string
{
    return leasingDay($ymd)->modify(sprintf('%+d days', $days))->format('Y-m-d');
}

/** Days from $start to $end, both included, by PHP's own calendar. */
function leasingInclusiveDays(DateTimeInterface $start, DateTimeInterface $end): int
{
    return (int) leasingDay(leasingYmd($start))->diff(leasingDay(leasingYmd($end)))->days + 1;
}

/**
 * What the rules say period $n of a cycle starts on: $n × 1 or 7 days for
 * day cycles; otherwise the month $n × months after the anchor's, on the
 * anchor's day, or that month's last day when it is shorter.
 */
function leasingExpectedStart(string $anchor, BillingCycle $cycle, int $n): string
{
    if ($cycle->days() !== null) {
        return leasingShift($anchor, $n * $cycle->days());
    }

    $month = leasingDay(substr($anchor, 0, 8).'01')->modify(sprintf('%+d months', $n * (int) $cycle->months()));
    $day = min((int) substr($anchor, 8, 2), (int) $month->format('t'));

    return $month->format('Y-m-').sprintf('%02d', $day);
}

/**
 * Records a mismatch instead of failing at once (see the property tests).
 *
 * @param  list<string>  $failures
 */
function leasingSame(array &$failures, string $actual, string $expected, string $where): void
{
    if ($actual !== $expected) {
        $failures[] = "{$where}: expected {$expected}, got {$actual}";
    }
}
