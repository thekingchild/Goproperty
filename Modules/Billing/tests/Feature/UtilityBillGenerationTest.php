<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Billing\Bills\UtilityBillGenerator;
use Modules\Billing\Enums\BillableType;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Enums\UtilityBillingHold;
use Modules\Billing\Events\BillIssued;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Billing\Utilities\MeteredCharge;
use Modules\Billing\Utilities\MeterValue;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Leases\LeaseService;

require_once __DIR__.'/utility-bill-helpers.php';

/*
 * P03-06b: utility bills (ARCHITECTURE.md § 6.4 "How utility bills are
 * built"), made by the scheduled `bills-generate` job after the rent bills:
 * each unit's utility on its own cycle (the calculator's periods from the
 * date it applies), flat utilities their amount each period, metered ones
 * (current − previous reading) × rate in kobo for the readings of the
 * period, never estimated, linked to their bill, once per period, to the
 * tenant of the unit's lease.
 */

beforeEach(function () {
    // Bills issue their invoice PDF on the pdf queue (P03-05c); UtilityBillDocumentTest renders them.
    holdInvoicePdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-12-20 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->flat1 = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']);
    $this->flat2 = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 2']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com']);
    $this->tunde = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348035556666', 'email' => 'tunde.bakare@example.com']);
    // Electricity: metered, ₦250 per kWh, monthly. Security levy: flat ₦25,000, monthly.
    $this->electricity = electricityIn($this->acme, $this->owner);
    $this->security = utilityTypeIn($this->acme, $this->owner);
});

afterEach(fn () => billsFreshRequest());

describe('acceptance: metered bill = (current − previous reading) × rate in kobo', function () {
    it('bills the readings of the period after it ends: the consumption times the rate kept on each reading, exactly', function () {
        $lease = monthlyLease($this->acme, $this->owner, $this->flat1, $this->kemi);
        $meter = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity, ['startingReading' => '1,000']);
        $this->travelTo('2027-01-31 18:00:00');
        $january = readMeter($this->acme, $this->owner, $meter, '2027-01-31', '1234.567');

        // The period is not over on its last day: nothing is billed for it yet.
        onUtilityBillingDay('2027-01-31');
        runBillGeneration();
        expect(utilityBillsOf($this->acme))->toHaveCount(0);

        onUtilityBillingDay('2027-02-01');
        runBillGeneration();
        $bill = utilityBillsOf($this->acme)->sole();
        $line = billLinesOf($this->acme, $bill)->sole();

        // 1,234.567 − 1,000 = 234.567 kWh × ₦250 = ₦58,641.75.
        expect($bill->amount_kobo->kobo)->toBe(5_864_175)
            ->and($bill->type)->toBe(BillType::Utility)
            ->and($bill->billable_type)->toBe(BillableType::Unit)
            ->and($bill->billable_id)->toBe($this->flat1->id)
            ->and($bill->unit_id)->toBe($this->flat1->id)
            ->and($bill->property_id)->toBe($this->palm->id)
            ->and($bill->unit_utility_setting_id)->toBe($meter->id)
            ->and($bill->tenant_profile_id)->toBe($this->kemi->id)
            ->and($bill->lease_id)->toBeNull()
            ->and($bill->description)->toBe('Electricity, 01/01/2027 to 31/01/2027')
            ->and($bill->period_start?->toDateString())->toBe('2027-01-01')
            ->and($bill->period_end?->toDateString())->toBe('2027-01-31')
            // Due the lead window (7 days) after it is made.
            ->and($bill->due_date->toDateString())->toBe('2027-02-08')
            ->and($bill->status)->toBe(BillStatus::Unpaid)
            ->and($bill->details['lease']['number'] ?? null)->toBe(storedLease($this->acme, $lease)->contract_number)
            ->and($bill->details['unit'] ?? null)->toBe('Flat 1')
            ->and($line->label)->toBe('Electricity')
            ->and($line->amount_kobo->kobo)->toBe(5_864_175)
            ->and($line->note)->toBe('Read 31/01/2027: 1,000 to 1,234.567, 234.567 kWh at ₦250')
            ->and($line->meta)->toEqual([
                'utility_setting_id' => $meter->id,
                'utility' => 'ELEC',
                'charging' => 'metered',
                'reading_id' => $january->id,
                'reading_date' => '2027-01-31',
                'previous' => '1000.000',
                'current' => '1234.567',
                'consumption' => '234.567',
                'unit' => 'kwh',
                'rate_kobo' => 25_000,
                'meter_replaced' => false,
            ])
            // The reading used is linked to its bill.
            ->and(storedReading($this->acme, $january)->bill_id)->toBe($bill->id)
            ->and(storedSetting($this->acme, $meter)->next_billing_date?->toDateString())->toBe('2027-02-01');

        // The rate changes to ₦3.33 per kWh: February's readings carry it (and January's keeps ₦250).
        changeUtility($this->acme, $this->owner, $meter, ['amount' => '3.33']);
        $this->travelTo('2027-02-14 18:00:00');
        $half = readMeter($this->acme, $this->owner, $meter, '2027-02-14', '1235.067');
        $this->travelTo('2027-02-28 18:00:00');
        $thousandth = readMeter($this->acme, $this->owner, $meter, '2027-02-28', '1236.068');

        onUtilityBillingDay('2027-03-01');
        runBillGeneration();
        $february = utilityBillsOf($this->acme)->last();

        // 0.5 kWh × 333 kobo = 166.5 → 167 (halves away from zero); 1.001 kWh × 333 kobo = 333.333 → 333.
        expect(utilityBillsOf($this->acme))->toHaveCount(2)
            ->and(billLinesOf($this->acme, $february)->map(fn ($item): int => $item->amount_kobo->kobo)->all())->toBe([167, 333])
            ->and($february->amount_kobo->kobo)->toBe(500)
            ->and($february->period_start?->toDateString())->toBe('2027-02-01')
            ->and(storedReading($this->acme, $half)->bill_id)->toBe($february->id)
            ->and(storedReading($this->acme, $thousandth)->bill_id)->toBe($february->id)
            ->and(storedReading($this->acme, $january)->bill_id)->toBe($bill->id)
            ->and(billLinesOf($this->acme, $february)->first()->meta['rate_kobo'])->toBe(333);
    });

    it('bills large and fractional values exactly, in kobo', function (string $value, string $rate, int $kobo) {
        $water = utilityTypeIn($this->acme, $this->owner, ['name' => 'Water', 'code' => 'WATER', 'chargingMode' => 'metered', 'unitOfMeasure' => 'litre', 'amount' => $rate]);
        monthlyLease($this->acme, $this->owner, $this->flat1, $this->kemi);
        $meter = utilityOn($this->acme, $this->owner, $this->flat1, $water, ['startingReading' => '0']);
        $this->travelTo('2027-01-31 18:00:00');
        readMeter($this->acme, $this->owner, $meter, '2027-01-31', $value);

        onUtilityBillingDay('2027-02-01');
        runBillGeneration();
        $bill = utilityBillsOf($this->acme)->sole();

        expect($bill->amount_kobo->kobo)->toBe($kobo)
            ->and(storedBill($this->acme, $bill)->amount_kobo->kobo)->toBe($kobo)
            ->and(billLinesOf($this->acme, $bill)->sole()->amount_kobo->kobo)->toBe($kobo);
    })->with([
        'half a litre at ₦3.33 (166.5 kobo, halves away from zero)' => ['0.5', '3.33', 167],
        '1.001 litres at ₦3.33 (333.333 kobo)' => ['1.001', '3.33', 333],
        'a thousandth at ₦5 (half a kobo)' => ['0.001', '5', 1],
        '987,654,321.987 litres at ₦1,234.56' => ['987,654,321.987', '1,234.56', 121_931_851_975_227],
        'the largest meter value near the largest amount' => ['999,999,999,999.999', '92,233.72', 9_223_371_999_999_990_777],
    ]);

    it('works out consumption × rate in kobo with integers only, and fails loudly beyond the largest amount', function (int $thousandths, int $rate, int $kobo) {
        expect(MeteredCharge::of(MeterValue::ofThousandths($thousandths), Money::kobo($rate))->kobo)->toBe($kobo);
    })->with([
        [234_567, 25_000, 5_864_175],
        [500, 333, 167],
        [1_001, 333, 333],
        [1, 499, 0],
        [1, 500, 1],
        [0, 25_000, 0],
        [12_345_678_901, 7_777, 96_012_344_813],
        [987_654_321_987, 123_456, 121_931_851_975_227],
        [999_999_999_999_999, 9_223_372, 9_223_371_999_999_990_777],
    ]);

    it('refuses an amount larger than money holds instead of overflowing', function () {
        expect(fn () => MeteredCharge::of(MeterValue::ofThousandths(999_999_999_999_999), Money::kobo(10_000_000)))->toThrow(OverflowException::class);
    });
});

it('acceptance: flat utilities bill each cycle: the calculator\'s periods from the date they apply, each on its billing day', function () {
    monthlyLease($this->acme, $this->owner, $this->flat1, $this->kemi, ['billingCycle' => 'annual', 'rent' => '1,200,000']);
    monthlyLease($this->acme, $this->owner, $this->flat2, $this->tunde, ['billingCycle' => 'annual', 'rent' => '1,200,000']);
    // From 31 January: periods anchored to the 31st, on the last day of shorter months.
    $monthly = utilityOn($this->acme, $this->owner, $this->flat1, $this->security, ['effectiveFrom' => '2027-01-31']);
    $quarterly = utilityOn($this->acme, $this->owner, $this->flat2, $this->security, ['cycle' => 'quarterly', 'amount' => '60,000']);

    $expectedMonthly = [
        ['2027-01-31', '2027-02-27'],
        ['2027-02-28', '2027-03-30'],
        ['2027-03-31', '2027-04-29'],
        ['2027-04-30', '2027-05-30'],
        ['2027-05-31', '2027-06-29'],
        ['2027-06-30', '2027-07-30'],
    ];
    $expectedQuarterly = [['2027-01-01', '2027-03-31'], ['2027-04-01', '2027-06-30'], ['2027-07-01', '2027-09-30']];
    expect(array_map(fn (array $row): array => [$row[0], $row[1]], calculatorPeriods('2027-01-31', BillingCycle::Monthly, 6)))->toBe($expectedMonthly)
        ->and(array_map(fn (array $row): array => [$row[0], $row[1]], calculatorPeriods('2027-01-01', BillingCycle::Quarterly, 3)))->toBe($expectedQuarterly);

    // Each period is billed on its billing day (7 days before it starts), not the day before.
    $schedule = [];
    foreach ($expectedMonthly as $index => [$start]) {
        $schedule[] = [$monthly, $index + 1, $start];
    }
    foreach ($expectedQuarterly as $index => [$start]) {
        $schedule[] = [$quarterly, $index + 1, $start];
    }
    usort($schedule, fn (array $a, array $b): int => $a[2] <=> $b[2]);

    foreach ($schedule as [$setting, $count, $start]) {
        $billingDay = CarbonImmutable::parse($start)->subDays(7)->toDateString();
        onUtilityBillingDay(CarbonImmutable::parse($billingDay)->subDay()->toDateString());
        runBillGeneration();
        expect(utilityBillsOf($this->acme, $setting))->toHaveCount($count - 1);

        onUtilityBillingDay($billingDay);
        runBillGeneration();
        expect(utilityBillsOf($this->acme, $setting))->toHaveCount($count)
            ->and(utilityBillsOf($this->acme, $setting)->last()->period_start?->toDateString())->toBe($start);
    }

    expect(utilityBilledPeriods($this->acme, $monthly))->toBe(array_map(
        fn (array $period): array => [$period[0], $period[1], $period[0], 2_500_000, $this->kemi->id],
        $expectedMonthly,
    ))
        ->and(utilityBilledPeriods($this->acme, $quarterly))->toBe(array_map(
            fn (array $period): array => [$period[0], $period[1], $period[0], 6_000_000, $this->tunde->id],
            $expectedQuarterly,
        ));

    $first = utilityBillsOf($this->acme, $monthly)->first();
    $line = billLinesOf($this->acme, $first)->sole();
    expect($first->description)->toBe('Security levy, 31/01/2027 to 27/02/2027')
        ->and($line->label)->toBe('Security levy')
        ->and($line->note)->toBe('Flat charge, monthly')
        ->and($line->period_start?->toDateString())->toBe('2027-01-31')
        ->and($line->meta)->toEqual(['utility_setting_id' => $monthly->id, 'utility' => 'SECURITY', 'charging' => 'flat', 'cycle' => 'monthly'])
        ->and(utilityBillsOf($this->acme)->pluck('lease_id')->unique()->all())->toBe([null])
        // Rent and utility bills share the invoice sequence, per year of issue: in 2026 the two
        // yearly rent bills and the first quarter's levy, in 2027 the other eight levies.
        ->and(utilityBillsOf($this->acme)->pluck('number')->first())->toBe('INV-ADR-2026-000003')
        ->and(utilityBillsOf($this->acme)->pluck('number')->last())->toBe('INV-ADR-2027-000008');
});

describe('acceptance: a rerun creates no duplicates', function () {
    it('bills each period once when the job runs again, the same day or later', function () {
        monthlyLease($this->acme, $this->owner, $this->flat1, $this->kemi);
        $flat = utilityOn($this->acme, $this->owner, $this->flat1, $this->security);
        $meter = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity);
        $issued = [];
        Event::listen(BillIssued::class, function (BillIssued $event) use (&$issued): void {
            $issued[] = $event->billId;
        });
        $this->travelTo('2027-01-31 18:00:00');
        $reading = readMeter($this->acme, $this->owner, $meter, '2027-01-31', '120');

        foreach (['2027-01-25', '2027-01-25', '2027-02-01', '2027-02-01', '2027-02-01', '2027-02-02', '2027-02-10'] as $day) {
            onUtilityBillingDay($day);
            runBillGeneration();
        }

        // January and February of the flat utility (February's bill date is 25 January), January's electricity.
        expect(utilityBillsOf($this->acme, $flat)->map(fn (Bill $bill): string => (string) $bill->period_start?->toDateString())->all())->toBe(['2027-01-01', '2027-02-01'])
            ->and(utilityBillsOf($this->acme, $meter)->map(fn (Bill $bill): string => (string) $bill->period_start?->toDateString())->all())->toBe(['2027-01-01'])
            ->and(utilityBillsOf($this->acme))->toHaveCount(3)
            ->and(collect($issued)->intersect(utilityBillsOf($this->acme)->pluck('id'))->values()->all())->toBe(utilityBillsOf($this->acme)->pluck('id')->all())
            ->and(AuditEntry::query()->where('event', 'bill.issued')->whereIn('subject_id', utilityBillsOf($this->acme)->pluck('id'))->count())->toBe(3)
            ->and(storedReading($this->acme, $reading)->bill_id)->toBe(utilityBillsOf($this->acme, $meter)->sole()->id);
    });

    it('never bills a period twice, even for a setting put back a period, and the database refuses a second bill', function () {
        monthlyLease($this->acme, $this->owner, $this->flat1, $this->kemi);
        $flat = utilityOn($this->acme, $this->owner, $this->flat1, $this->security);
        onUtilityBillingDay('2027-01-25');
        runBillGeneration();
        expect(utilityBillsOf($this->acme, $flat))->toHaveCount(2);

        // The setting is put back to January: the run finds January's and February's bills and only moves it on.
        inOrganization($this->acme, fn () => UnitUtilitySetting::query()->whereKey($flat->id)->update(['next_billing_date' => '2027-01-01']));
        runBillGeneration();
        expect(utilityBillsOf($this->acme, $flat))->toHaveCount(2)
            ->and(storedSetting($this->acme, $flat)->next_billing_date?->toDateString())->toBe('2027-03-01');

        $january = utilityBillsOf($this->acme, $flat)->first();
        $copy = collect(DB::table('bills')->where('id', $january->id)->first())->except('id')->all();
        $copy['number'] = 'INV-ADR-2027-999999';
        $copy['payment_reference'] = 'GP-ZZZZZZZ';
        $copy['verification_code'] = 'ZZZZZZZZZZ';

        expect(fn () => DB::table('bills')->insert($copy))->toThrow(UniqueConstraintViolationException::class, 'bills_utility_setting_period_unique');
    });

    it('links each reading to one bill only, and leaves later readings for later bills', function () {
        monthlyLease($this->acme, $this->owner, $this->flat1, $this->kemi);
        $meter = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity);
        $this->travelTo('2027-01-15 18:00:00');
        $mid = readMeter($this->acme, $this->owner, $meter, '2027-01-15', '40');
        $this->travelTo('2027-01-31 18:00:00');
        $end = readMeter($this->acme, $this->owner, $meter, '2027-01-31', '100');
        $this->travelTo('2027-02-01 00:05:00');
        $next = readMeter($this->acme, $this->owner, $meter, '2027-02-01', '101');

        onUtilityBillingDay('2027-02-01');
        runBillGeneration();
        runBillGeneration();
        $january = utilityBillsOf($this->acme, $meter)->sole();

        expect(billLinesOf($this->acme, $january)->map(fn ($item): int => $item->meta['reading_id'])->all())->toBe([$mid->id, $end->id])
            ->and($january->amount_kobo->kobo)->toBe(2_500_000)
            ->and(storedReading($this->acme, $mid)->bill_id)->toBe($january->id)
            ->and(storedReading($this->acme, $end)->bill_id)->toBe($january->id)
            ->and(storedReading($this->acme, $next)->bill_id)->toBeNull()
            ->and(inOrganization($this->acme, fn () => UtilityReading::query()->where('bill_id', $january->id)->count()))->toBe(2);

        // A billed reading never changes.
        expect(fn () => inOrganization($this->acme, fn () => storedReading($this->acme, $end)->forceFill(['note' => 'changed'])->save()))->toThrow(LogicException::class);
    });
});

it('acceptance: handles a replaced meter: the old meter\'s last reading and the new meter from its first value', function () {
    monthlyLease($this->acme, $this->owner, $this->flat1, $this->kemi);
    $meter = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity, ['startingReading' => '1,000']);
    $this->travelTo('2027-01-31 18:00:00');
    readMeter($this->acme, $this->owner, $meter, '2027-01-31', '1500');
    $this->travelTo('2027-02-10 18:00:00');
    $oldLast = readMeter($this->acme, $this->owner, $meter, '2027-02-10', '1560');
    $this->travelTo('2027-02-11 18:00:00');
    $newFirst = readMeter($this->acme, $this->owner, $meter, '2027-02-11', '25', ['meterReplaced' => true, 'newMeterStart' => '0']);
    $this->travelTo('2027-02-28 18:00:00');
    $newEnd = readMeter($this->acme, $this->owner, $meter, '2027-02-28', '90');

    onUtilityBillingDay('2027-03-01');
    runBillGeneration();
    [$january, $february] = utilityBillsOf($this->acme, $meter)->all();
    $lines = billLinesOf($this->acme, $february);

    // January: 500 kWh. February: 60 kWh on the old meter, then 25 and 65 on the new one (never 25 − 1,560).
    expect($january->amount_kobo->kobo)->toBe(12_500_000)
        ->and($lines->map(fn ($item): array => [$item->meta['previous'], $item->meta['current'], $item->meta['consumption'], $item->meta['meter_replaced'], $item->amount_kobo->kobo])->all())->toBe([
            ['1500.000', '1560.000', '60.000', false, 1_500_000],
            ['0.000', '25.000', '25.000', true, 625_000],
            ['25.000', '90.000', '65.000', false, 1_625_000],
        ])
        ->and($february->amount_kobo->kobo)->toBe(3_750_000)
        ->and($lines->get(1)->note)->toBe('Read 11/02/2027: 0 to 25, 25 kWh at ₦250 (new meter)')
        ->and(collect([$oldLast, $newFirst, $newEnd])->map(fn (UtilityReading $reading): ?int => storedReading($this->acme, $reading)->bill_id)->unique()->all())->toBe([$february->id]);
});

describe('acceptance: no reading → no estimated bill', function () {
    it('waits for a reading, never estimates, and bills a late reading with a later period', function () {
        monthlyLease($this->acme, $this->owner, $this->flat1, $this->kemi);
        $meter = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity, ['startingReading' => '1,000']);

        // January ends without a reading: no bill, the utility shows it is waiting.
        onUtilityBillingDay('2027-02-01');
        runBillGeneration();
        $waiting = storedSetting($this->acme, $meter);
        expect(utilityBillsOf($this->acme))->toHaveCount(0)
            ->and($waiting->billing_hold)->toBe(UtilityBillingHold::NoReading)
            ->and($waiting->billing_hold_since?->toDateString())->toBe('2027-01-01')
            ->and($waiting->next_billing_date)->toBeNull();
        utilitiesPage($this->owner, '/units')->assertOk()->assertSee('data-test="billing-hold-'.$meter->id.'"', false)->assertSee('Waiting for a meter reading')->assertSee('since 01/01/2027');

        // A reading taken on 3 February belongs to February: January still has none and still waits.
        $this->travelTo('2027-02-03 18:00:00');
        $late = readMeter($this->acme, $this->owner, $meter, '2027-02-03', '1050');
        onUtilityBillingDay('2027-02-04');
        runBillGeneration();
        expect(utilityBillsOf($this->acme))->toHaveCount(0);

        // Once February has ended too, January closes without a bill and February bills the reading.
        onUtilityBillingDay('2027-03-01');
        runBillGeneration();
        $february = utilityBillsOf($this->acme)->sole();
        expect($february->period_start?->toDateString())->toBe('2027-02-01')
            ->and($february->amount_kobo->kobo)->toBe(1_250_000)
            ->and(storedReading($this->acme, $late)->bill_id)->toBe($february->id)
            ->and(storedSetting($this->acme, $meter)->billing_hold)->toBeNull()
            ->and(storedSetting($this->acme, $meter)->next_billing_date?->toDateString())->toBe('2027-03-01');

        // March without a reading: nothing, waiting again.
        onUtilityBillingDay('2027-04-01');
        runBillGeneration();
        expect(utilityBillsOf($this->acme))->toHaveCount(1)
            ->and(storedSetting($this->acme, $meter)->billing_hold)->toBe(UtilityBillingHold::NoReading)
            ->and(storedSetting($this->acme, $meter)->billing_hold_since?->toDateString())->toBe('2027-03-01');

        // April's only reading shows nothing used: no bill (nothing is owed); May's bill takes it with May's reading.
        $this->travelTo('2027-04-02 18:00:00');
        $unchanged = readMeter($this->acme, $this->owner, $meter, '2027-04-02', '1050');
        onUtilityBillingDay('2027-05-01');
        runBillGeneration();
        expect(utilityBillsOf($this->acme))->toHaveCount(1)
            ->and(storedReading($this->acme, $unchanged)->bill_id)->toBeNull();

        $this->travelTo('2027-05-20 18:00:00');
        $may = readMeter($this->acme, $this->owner, $meter, '2027-05-20', '1060');
        onUtilityBillingDay('2027-06-01');
        runBillGeneration();
        $mayBill = utilityBillsOf($this->acme)->last();

        expect(utilityBillsOf($this->acme))->toHaveCount(2)
            ->and($mayBill->period_start?->toDateString())->toBe('2027-05-01')
            ->and(billLinesOf($this->acme, $mayBill)->map(fn ($item): int => $item->amount_kobo->kobo)->all())->toBe([0, 250_000])
            ->and(storedReading($this->acme, $unchanged)->bill_id)->toBe($mayBill->id)
            ->and(storedReading($this->acme, $may)->bill_id)->toBe($mayBill->id)
            // Never a bill for a period without its reading: January, March and April have none.
            ->and(utilityBillsOf($this->acme)->map(fn (Bill $bill): string => (string) $bill->period_start?->toDateString())->all())->toBe(['2027-02-01', '2027-05-01']);
    });
});

describe('who pays: the tenant of the unit\'s lease', function () {
    it('skips and flags a period no lease covers, and starts billing once a tenant is on a lease', function () {
        $levy = utilityOn($this->acme, $this->owner, $this->flat2, $this->security);

        // Vacant: no bill, flagged.
        onUtilityBillingDay('2026-12-25');
        runBillGeneration();
        expect(utilityBillsOf($this->acme))->toHaveCount(0)
            ->and(storedSetting($this->acme, $levy)->billing_hold)->toBe(UtilityBillingHold::NoTenant)
            ->and(storedSetting($this->acme, $levy)->billing_hold_since?->toDateString())->toBe('2027-01-01');
        utilitiesPage($this->owner, '/units')->assertOk()->assertSee('Not billed: no tenant on a lease');

        // January ends with nobody: closed without a bill; February waits.
        onUtilityBillingDay('2027-02-01');
        runBillGeneration();
        expect(utilityBillsOf($this->acme))->toHaveCount(0)
            ->and(storedSetting($this->acme, $levy)->next_billing_date?->toDateString())->toBe('2027-02-01')
            ->and(storedSetting($this->acme, $levy)->billing_hold_since?->toDateString())->toBe('2027-01-01');

        // Tunde's lease from 15 February does not cover February's first day: February is not his.
        $this->travelTo('2027-02-10 09:00:00');
        monthlyLease($this->acme, $this->owner, $this->flat2, $this->tunde, ['startDate' => '2027-02-15']);
        onUtilityBillingDay('2027-02-22');
        runBillGeneration();
        expect(utilityBillsOf($this->acme))->toHaveCount(0);

        onUtilityBillingDay('2027-03-01');
        runBillGeneration();
        expect(utilityBilledPeriods($this->acme, $levy))->toBe([['2027-03-01', '2027-03-31', '2027-03-01', 2_500_000, $this->tunde->id]])
            ->and(storedSetting($this->acme, $levy)->billing_hold)->toBeNull();
    });

    it('bills the outgoing tenant up to their lease\'s end and the next tenant from theirs, never one for the other', function () {
        $january = monthlyLease($this->acme, $this->owner, $this->flat1, $this->kemi, ['endDate' => '2027-01-31']);
        $levy = utilityOn($this->acme, $this->owner, $this->flat1, $this->security);
        $meter = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity, ['startingReading' => '1,000']);

        // February's levy is due to be billed on 25 January, but Kemi's lease ends on 31 January.
        onUtilityBillingDay('2027-01-25');
        runBillGeneration();
        $this->travelTo('2027-01-31 18:00:00');
        $final = readMeter($this->acme, $this->owner, $meter, '2027-01-31', '1100');

        // Kemi's lease expires; her last meter reading is still hers.
        $this->travelTo('2027-02-01 00:05:00');
        inOrganization($this->acme, fn () => app(LeaseService::class)->expire(storedLease($this->acme, $january)));
        onUtilityBillingDay('2027-02-01');
        runBillGeneration();

        expect(utilityBilledPeriods($this->acme, $levy))->toBe([['2027-01-01', '2027-01-31', '2027-01-01', 2_500_000, $this->kemi->id]])
            ->and(utilityBilledPeriods($this->acme, $meter))->toBe([['2027-01-01', '2027-01-31', '2027-02-08', 2_500_000, $this->kemi->id]])
            ->and(storedReading($this->acme, $final)->bill_id)->toBe(utilityBillsOf($this->acme, $meter)->sole()->id);

        // Tunde moves in on 5 February. A reading taken before he came is never his.
        $this->travelTo('2027-02-04 18:00:00');
        $between = readMeter($this->acme, $this->owner, $meter, '2027-02-04', '1110');
        monthlyLease($this->acme, $this->owner, $this->flat1, $this->tunde, ['startDate' => '2027-02-05']);
        $this->travelTo('2027-02-28 18:00:00');
        $tundes = readMeter($this->acme, $this->owner, $meter, '2027-02-28', '1150');

        onUtilityBillingDay('2027-03-01');
        runBillGeneration();

        expect(utilityBilledPeriods($this->acme, $meter)[1])->toBe(['2027-02-01', '2027-02-28', '2027-03-08', 1_000_000, $this->tunde->id])
            ->and(storedReading($this->acme, $tundes)->bill_id)->not->toBeNull()
            ->and(storedReading($this->acme, $between)->bill_id)->toBeNull()
            // The levy: February closed without a bill (nobody's lease covers 1 February), March is Tunde's.
            ->and(utilityBilledPeriods($this->acme, $levy))->toBe([
                ['2027-01-01', '2027-01-31', '2027-01-01', 2_500_000, $this->kemi->id],
                ['2027-03-01', '2027-03-31', '2027-03-01', 2_500_000, $this->tunde->id],
            ]);
    });
});

it('bills nothing while a utility is switched off, and resumes from the next period; a changed cycle starts at its next whole period', function () {
    monthlyLease($this->acme, $this->owner, $this->flat1, $this->kemi);
    $levy = utilityOn($this->acme, $this->owner, $this->flat1, $this->security);
    onUtilityBillingDay('2026-12-25');
    runBillGeneration();

    $this->travelTo('2027-01-10 09:00:00');
    changeUtility($this->acme, $this->owner, $levy, ['active' => false]);
    foreach (['2027-01-25', '2027-02-25'] as $day) {
        onUtilityBillingDay($day);
        runBillGeneration();
    }
    expect(utilityBillsOf($this->acme, $levy))->toHaveCount(1);

    // Switched on again on 15 March: February and March are not billed; April is, on its billing day.
    $this->travelTo('2027-03-15 09:00:00');
    changeUtility($this->acme, $this->owner, $levy, ['active' => true]);
    expect(storedSetting($this->acme, $levy)->next_billing_date?->toDateString())->toBe('2027-04-01');
    onUtilityBillingDay('2027-03-25');
    runBillGeneration();
    expect(utilityBillsOf($this->acme, $levy)->map(fn (Bill $bill): string => (string) $bill->period_start?->toDateString())->all())->toBe(['2027-01-01', '2027-04-01']);

    // Quarterly from now on (counted from 1 January): April–June does not fit, so it continues with July–September.
    $this->travelTo('2027-04-10 09:00:00');
    changeUtility($this->acme, $this->owner, $levy, ['cycle' => 'quarterly']);
    onUtilityBillingDay('2027-06-24');
    runBillGeneration();
    expect(utilityBilledPeriods($this->acme, $levy))->toBe([
        ['2027-01-01', '2027-01-31', '2027-01-01', 2_500_000, $this->kemi->id],
        ['2027-04-01', '2027-04-30', '2027-04-01', 2_500_000, $this->kemi->id],
        ['2027-07-01', '2027-09-30', '2027-07-01', 2_500_000, $this->kemi->id],
    ]);
});

it('bills nothing for an archived utility or an archived unit', function () {
    monthlyLease($this->acme, $this->owner, $this->flat1, $this->kemi);
    utilityOn($this->acme, $this->owner, $this->flat1, $this->security);
    inOrganization($this->acme, fn () => $this->security->forceFill(['active' => false])->save());
    onUtilityBillingDay('2026-12-25');
    runBillGeneration();

    expect(utilityBillsOf($this->acme))->toHaveCount(0);
});

describe('cross-organization probes', function () {
    it('bills each organization\'s utilities in its own context, with its own numbers, tenants and readings', function () {
        $other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
        $otherOwner = joinAs(User::factory()->create(), $other);
        $oak = buildingIn($other, ['name' => 'Oak Lodge']);
        $oakFlat = unitIn($other, $oak, ['unit_label' => 'Oak 1']);
        $bisi = tenantIn($other, ['first_name' => 'Bisi', 'last_name' => 'Okon', 'phone' => '+2348039990000', 'email' => 'bisi.okon@example.com']);
        $otherPower = electricityIn($other, $otherOwner, ['amount' => '100']);
        monthlyLease($other, $otherOwner, $oakFlat, $bisi);
        $otherMeter = utilityOn($other, $otherOwner, $oakFlat, $otherPower);

        monthlyLease($this->acme, $this->owner, $this->flat1, $this->kemi);
        $meter = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity);
        $this->travelTo('2027-01-31 18:00:00');
        $ours = readMeter($this->acme, $this->owner, $meter, '2027-01-31', '10');
        $theirs = readMeter($other, $otherOwner, $otherMeter, '2027-01-31', '20');

        // Generating inside our organization touches only ours.
        onUtilityBillingDay('2027-02-01');
        inOrganization($this->acme, fn () => app(UtilityBillGenerator::class)->generate());
        expect(utilityBillsOf($this->acme))->toHaveCount(1)
            ->and(utilityBillsOf($other))->toHaveCount(0)
            ->and(storedReading($other, $theirs)->bill_id)->toBeNull()
            ->and(storedSetting($other, $otherMeter)->next_billing_date)->toBeNull();

        runBillGeneration();
        $ourBill = utilityBillsOf($this->acme)->sole();
        $theirBill = utilityBillsOf($other)->sole();

        expect($ourBill->organization_id)->toBe($this->acme->id)
            ->and($theirBill->organization_id)->toBe($other->id)
            ->and($ourBill->amount_kobo->kobo)->toBe(250_000)
            ->and($theirBill->amount_kobo->kobo)->toBe(200_000)
            ->and($theirBill->tenant_profile_id)->toBe($bisi->id)
            ->and(storedReading($this->acme, $ours)->bill_id)->toBe($ourBill->id)
            ->and(storedReading($other, $theirs)->bill_id)->toBe($theirBill->id)
            ->and(inOrganization($this->acme, fn () => Bill::query()->whereKey($theirBill->id)->exists()))->toBeFalse()
            ->and(inOrganization($this->acme, fn () => UtilityReading::query()->where('bill_id', $theirBill->id)->exists()))->toBeFalse();

        // Their utility bill is not ours to open, by its id under our address or theirs.
        billsPage($this->owner, '/'.$theirBill->id)->assertNotFound();
        billsFreshRequest();
        $this->actingAs($this->owner)->get("/o/acme-homes/bills/{$theirBill->id}/invoice")->assertNotFound();
        billsFreshRequest();
        $this->actingAs($this->owner)->get("/o/other-realty/bills/{$theirBill->id}/invoice")->assertNotFound();
        billsPage($this->owner)->assertOk()->assertDontSee($theirBill->number);
    });

    it('keeps billing suspended organizations\' utilities, and nothing for a cancelled one', function () {
        monthlyLease($this->acme, $this->owner, $this->flat1, $this->kemi);
        utilityOn($this->acme, $this->owner, $this->flat1, $this->security);
        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();
        onUtilityBillingDay('2026-12-25');
        runBillGeneration();
        expect(utilityBillsOf($this->acme))->toHaveCount(1);

        $this->acme->forceFill(['status' => OrganizationStatus::Cancelled])->save();
        onUtilityBillingDay('2027-01-25');
        runBillGeneration();
        expect(utilityBillsOf($this->acme))->toHaveCount(1);
    });
});

it('shows utility bills on the bills pages and in the export, and links a billed reading to its bill', function () {
    monthlyLease($this->acme, $this->owner, $this->flat1, $this->kemi);
    $meter = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity, ['startingReading' => '1,000']);
    $this->travelTo('2027-01-31 18:00:00');
    $reading = readMeter($this->acme, $this->owner, $meter, '2027-01-31', '1010');
    onUtilityBillingDay('2027-02-01');
    runBillGeneration();
    $bill = utilityBillsOf($this->acme)->sole();
    $this->travelTo('2027-02-01 09:00:00');

    expect(listedBills(billsPage($this->owner, '?type=utility')->assertOk()))->toBe([$bill->id]);
    billsPage($this->owner, '/'.$bill->id)->assertOk()
        ->assertSee('Electricity')
        ->assertSee('Read 31/01/2027: 1,000 to 1,010, 10 kWh at ₦250')
        ->assertSee('Utility');
    utilitiesPage($this->owner, '/readings')->assertOk()->assertSee('data-test="reading-bill-'.$reading->id.'"', false)
        ->assertSee("/o/acme-homes/bills/{$bill->id}", false);

    $rows = exportRows($this->owner, '/bills?type=utility', 'bills', 'csv');
    expect(count($rows))->toBe(2)
        ->and($rows[1])->toBe([$bill->number, $bill->payment_reference, 'Utility', 'Unpaid', 'Kemi Adebayo', 'Palm Court', 'Flat 1', 'Electricity, 01/01/2027 to 31/01/2027', '2027-01-01', '2027-01-31', '2027-02-08', '2500.00', '0.00', '2500.00', '2027-02-01 01:10', '', '']);
});
