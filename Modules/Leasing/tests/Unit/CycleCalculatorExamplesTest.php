<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Contracts\CycleCalculator as CycleCalculatorContract;
use Modules\Leasing\CycleCalculator;

/*
 * P03-02: the worked examples of ARCHITECTURE.md § 6.3 as explicit cases,
 * next to the property tests (CycleCalculatorPropertiesTest).
 */

/**
 * Periods 0..$count-1 as "start..end" strings.
 *
 * @return list<string>
 */
function leasingPeriods(string $anchor, BillingCycle $cycle, int $count): array
{
    $calculator = new CycleCalculator;
    $periods = [];

    for ($n = 0; $n < $count; $n++) {
        $period = $calculator->period(CarbonImmutable::parse($anchor), $cycle, $n);
        $periods[] = $period->start->toDateString().'..'.$period->end->toDateString();
    }

    return $periods;
}

it('bills monthly from 31 January on the month end, then back on the 31st (leap year)', function () {
    expect(leasingPeriods('2028-01-31', BillingCycle::Monthly, 6))->toBe([
        '2028-01-31..2028-02-28',
        '2028-02-29..2028-03-30',
        '2028-03-31..2028-04-29',
        '2028-04-30..2028-05-30',
        '2028-05-31..2028-06-29',
        '2028-06-30..2028-07-30',
    ]);
});

it('bills monthly from 31 January on 28 February in a common year', function () {
    expect(leasingPeriods('2027-01-31', BillingCycle::Monthly, 3))->toBe([
        '2027-01-31..2027-02-27',
        '2027-02-28..2027-03-30',
        '2027-03-31..2027-04-29',
    ]);
});

it('bills yearly from 29 February on 28 February in common years and 29 February in leap years', function () {
    expect(leasingPeriods('2028-02-29', BillingCycle::Annual, 5))->toBe([
        '2028-02-29..2029-02-27',
        '2029-02-28..2030-02-27',
        '2030-02-28..2031-02-27',
        '2031-02-28..2032-02-28',
        '2032-02-29..2033-02-27',
    ]);
});

it('treats 2100 as a common year and 2000 as a leap year', function () {
    expect(leasingPeriods('2096-02-29', BillingCycle::Annual, 5))->toBe([
        '2096-02-29..2097-02-27',
        '2097-02-28..2098-02-27',
        '2098-02-28..2099-02-27',
        '2099-02-28..2100-02-27',
        '2100-02-28..2101-02-27',
    ])->and(leasingPeriods('1999-02-28', BillingCycle::Annual, 2))->toBe([
        '1999-02-28..2000-02-27',
        '2000-02-28..2001-02-27',
    ]);
});

it('bills every two years from 29 February on 28 February, then 29 February again', function () {
    expect(leasingPeriods('2028-02-29', BillingCycle::Biennial, 3))->toBe([
        '2028-02-29..2030-02-27',
        '2030-02-28..2032-02-28',
        '2032-02-29..2034-02-27',
    ]);
});

it('bills quarterly from 30 August through the end of February and back to the 30th', function () {
    expect(leasingPeriods('2027-08-30', BillingCycle::Quarterly, 5))->toBe([
        '2027-08-30..2027-11-29',
        '2027-11-30..2028-02-28',
        '2028-02-29..2028-05-29',
        '2028-05-30..2028-08-29',
        '2028-08-30..2028-11-29',
    ])->and(leasingPeriods('2026-08-30', BillingCycle::Quarterly, 3))->toBe([
        '2026-08-30..2026-11-29',
        '2026-11-30..2027-02-27',
        '2027-02-28..2027-05-29',
    ]);
});

it('bills every six months from 31 August on the last day of February', function () {
    expect(leasingPeriods('2027-08-31', BillingCycle::SemiAnnual, 3))->toBe([
        '2027-08-31..2028-02-28',
        '2028-02-29..2028-08-30',
        '2028-08-31..2029-02-27',
    ]);
});

it('bills weekly from a Sunday in Sunday-to-Saturday weeks across the year end', function () {
    $calculator = new CycleCalculator;
    $sunday = CarbonImmutable::parse('2026-12-27');

    expect($sunday->isSunday())->toBeTrue()
        ->and(leasingPeriods('2026-12-27', BillingCycle::Weekly, 3))->toBe([
            '2026-12-27..2027-01-02',
            '2027-01-03..2027-01-09',
            '2027-01-10..2027-01-16',
        ]);

    foreach (range(0, 60) as $n) {
        $period = $calculator->period($sunday, BillingCycle::Weekly, $n);

        expect($period->start->isSunday())->toBeTrue()
            ->and($period->end->isSaturday())->toBeTrue()
            ->and($period->lengthInDays)->toBe(7);
    }
});

it('bills daily one calendar day at a time through 29 February', function () {
    expect(leasingPeriods('2028-02-27', BillingCycle::Daily, 4))->toBe([
        '2028-02-27..2028-02-27',
        '2028-02-28..2028-02-28',
        '2028-02-29..2028-02-29',
        '2028-03-01..2028-03-01',
    ])->and(leasingPeriods('2026-12-31', BillingCycle::Daily, 2))->toBe([
        '2026-12-31..2026-12-31',
        '2027-01-01..2027-01-01',
    ]);
});

it('finds the period a date falls in and the next period start', function () {
    $calculator = new CycleCalculator;
    $anchor = CarbonImmutable::parse('2028-01-31');

    $march = $calculator->periodContaining($anchor, BillingCycle::Monthly, CarbonImmutable::parse('2028-03-15 18:45'));
    $before = $calculator->periodContaining($anchor, BillingCycle::Monthly, CarbonImmutable::parse('2027-12-31'));

    expect($march->index)->toBe(1)
        ->and($march->start->toDateString())->toBe('2028-02-29')
        ->and($march->end->toDateString())->toBe('2028-03-30')
        ->and($calculator->periodContaining($anchor, BillingCycle::Monthly, CarbonImmutable::parse('2028-03-31'))->index)->toBe(2)
        ->and($before->index)->toBe(-1)
        ->and($before->start->toDateString())->toBe('2027-12-31')
        ->and($before->end->toDateString())->toBe('2028-01-30')
        // next_billing_date advances from a period start to the next one.
        ->and($calculator->nextPeriodStart($anchor, BillingCycle::Monthly, CarbonImmutable::parse('2028-02-29'))->toDateString())->toBe('2028-03-31')
        ->and($calculator->nextPeriodStart($anchor, BillingCycle::Monthly, CarbonImmutable::parse('2028-03-30'))->toDateString())->toBe('2028-03-31');
});

it('counts whole periods between two dates', function () {
    $calculator = new CycleCalculator;
    $anchor = CarbonImmutable::parse('2028-01-31');

    expect($calculator->periodsBetween($anchor, BillingCycle::Monthly, $anchor, CarbonImmutable::parse('2029-01-31')))->toBe(12)
        ->and($calculator->periodsBetween($anchor, BillingCycle::Monthly, $anchor, CarbonImmutable::parse('2029-01-30')))->toBe(11)
        ->and($calculator->periodsBetween($anchor, BillingCycle::Annual, $anchor, CarbonImmutable::parse('2038-01-31')))->toBe(10)
        ->and($calculator->periodsBetween($anchor, BillingCycle::Weekly, $anchor, CarbonImmutable::parse('2028-03-06')))->toBe(5)
        ->and(fn () => $calculator->periodsBetween($anchor, BillingCycle::Monthly, CarbonImmutable::parse('2028-03-01'), $anchor))
        ->toThrow(InvalidArgumentException::class);
});

it('puts the due date due_day_offset days after the start and generates the bill lead_days before it', function () {
    $calculator = new CycleCalculator;
    $start = CarbonImmutable::parse('2028-03-01');

    expect($calculator->dueDate($start)->toDateString())->toBe('2028-03-01')
        ->and($calculator->dueDate($start, 5)->toDateString())->toBe('2028-03-06')
        ->and($calculator->billingDate($start)->toDateString())->toBe('2028-02-23')
        ->and(CycleCalculatorContract::DEFAULT_LEAD_DAYS)->toBe(7)
        ->and($calculator->billingDate($start, 0)->toDateString())->toBe('2028-03-01')
        ->and($calculator->billingDate(CarbonImmutable::parse('2027-01-03'), 14)->toDateString())->toBe('2026-12-20')
        ->and(fn () => $calculator->dueDate($start, -1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $calculator->billingDate($start, -1))->toThrow(InvalidArgumentException::class);
});

it('works on calendar days: ignores the time and keeps the anchor time zone', function () {
    $calculator = new CycleCalculator;
    $anchor = CarbonImmutable::parse('2028-01-31 23:30:00', 'Africa/Lagos');
    $period = $calculator->period($anchor, BillingCycle::Monthly, 1);

    expect($period->start->format('Y-m-d H:i:s e'))->toBe('2028-02-29 00:00:00 Africa/Lagos')
        ->and($period->end->format('Y-m-d H:i:s e'))->toBe('2028-03-30 00:00:00 Africa/Lagos')
        ->and($period->contains(CarbonImmutable::parse('2028-03-30 23:59:59', 'Africa/Lagos')))->toBeTrue()
        ->and($period->contains(CarbonImmutable::parse('2028-03-31 00:00:00', 'Africa/Lagos')))->toBeFalse()
        // A plain DateTime (not Carbon) works too, and the input is never changed.
        ->and($calculator->periodStart(new DateTime('2028-01-31 08:00'), BillingCycle::Monthly, 1)->toDateString())->toBe('2028-02-29')
        ->and($anchor->format('Y-m-d H:i'))->toBe('2028-01-31 23:30');
});

it('labels every cycle and knows its length', function () {
    expect(array_map(fn (BillingCycle $cycle): string => $cycle->label(), BillingCycle::cases()))->toBe([
        'Daily', 'Weekly', 'Monthly', 'Quarterly', 'Every six months', 'Yearly', 'Every two years',
    ])->and(BillingCycle::values())->toBe([
        'daily', 'weekly', 'monthly', 'quarterly', 'semi_annual', 'annual', 'biennial',
    ]);

    foreach (BillingCycle::cases() as $cycle) {
        expect(($cycle->days() === null) !== ($cycle->months() === null))->toBeTrue($cycle->value);
    }
});
