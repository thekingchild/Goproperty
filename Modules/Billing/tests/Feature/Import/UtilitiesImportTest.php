<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Enums\ChargingMode;
use Modules\Billing\Enums\MeterUnit;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityType;
use Modules\Billing\Utilities\UtilityTypes;
use Modules\Import\Enums\ImportStatus;
use Modules\Leasing\Contracts\BillingCycle;

require_once __DIR__.'/../../../../Payments/tests/Feature/Import/helpers.php';

/*
 * P03-13b: the Utilities import sheet (DATA_IMPORT.md § 1 row 7, § 3)
 * through the real engine: utility types added when missing, units'
 * settings with overrides, a metered utility's last reading as the
 * starting reading, and `next_billing_date` always set, so the daily
 * `bills-generate` job never bills a period that started before the
 * import day (P03-06b: a setting with a past start would catch up).
 */

beforeEach(function () {
    Storage::fake('media');
    holdInvoicePdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-10-28 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com']), $this->acme);
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
});

/** The standard Utilities rows: a new flat levy paid until 31/08/2026 on Flat 1 and (₦12,000) on Flat 2, a new metered electricity on Flat 2. */
function standardUtilityRows(): array
{
    return [
        utilityRow(['Your ID' => 'UT-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Utility' => 'Security levy', 'Metered' => 'No', 'Amount' => '₦10,000', 'Billing cycle' => 'Monthly', 'Paid until' => '31/08/2026']),
        utilityRow(['Your ID' => 'UT-2', 'Building' => 'Palm Court', 'Unit' => 'Flat 2', 'Utility' => 'security levy', 'Amount' => '12,000']),
        utilityRow(['Your ID' => 'UT-3', 'Building' => 'Palm Court', 'Unit' => 'Flat 2', 'Utility' => 'Electricity', 'Metered' => 'Yes', 'Amount' => '250', 'Billing cycle' => 'Monthly', 'Unit of measure' => 'kWh', 'Last meter reading' => '1,234.5']),
    ];
}

/** The organization's setting with this Your ID. */
function importedSetting(Organization $organization, string $ref): UnitUtilitySetting
{
    return inOrganization($organization, fn (): UnitUtilitySetting => UnitUtilitySetting::query()->with('utilityType')->where('external_ref', $ref)->sole());
}

it('acceptance: imported utility settings are not back-billed: the bill run bills only periods from the import day on, while a page-added past start catches up', function () {
    Notification::fake();
    Mail::fake();

    $batch = portfolioDryRun($this->acme, $this->owner, balancesWorkbook(utilities: standardUtilityRows()));

    expect($batch->status)->toBe(ImportStatus::DryRunPassed)
        ->and(portfolioCounts($batch, 'utilities'))->toMatchArray(['create' => 3, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and(sheetIssues($batch, 'utilities'))->toBe([
            [2, 'Utility', 'D', 'warning', 'Adds the utility Security levy (flat, ₦10,000 monthly) to your utilities.'],
            [2, 'Paid until', 'J', 'warning', '01/09/2026 to 31/10/2026 is not paid, by Paid until, and GoProperty never bills the past: put what the tenant owes for it on the Opening balances sheet. Billing starts on 01/11/2026.'],
            [4, 'Utility', 'D', 'warning', 'Adds the utility Electricity (₦250 per kWh, monthly) to your utilities.'],
        ])
        ->and(inOrganization($this->acme, fn () => UtilityType::query()->count()))->toBe(0);

    $batch = portfolioCommit($this->acme, $this->owner, $batch);
    $levy1 = importedSetting($this->acme, 'UT-1');
    $levy2 = importedSetting($this->acme, 'UT-2');
    $power = importedSetting($this->acme, 'UT-3');
    [$adaezeLease, $bayoLease] = leasesOf($this->acme)->all();

    // Two utilities added, the batch's; settings with overrides only where they differ, the dates set, the last reading kept.
    $types = inOrganization($this->acme, fn () => UtilityType::query()->orderBy('id')->get());
    expect($batch->result_counts['targets']['utilities'])->toMatchArray(['create' => 3, 'skip' => 0])
        ->and($types->map(fn ($type) => [$type->name, $type->code, $type->charging_mode, $type->unit_of_measure, $type->default_amount_kobo->kobo, $type->default_cycle, $type->import_batch_id])->all())->toBe([
            ['Security levy', 'SECURITY-LEVY', ChargingMode::Flat, null, 1_000_000, BillingCycle::Monthly, $batch->id],
            ['Electricity', 'ELECTRICITY', ChargingMode::Metered, MeterUnit::Kwh, 25_000, BillingCycle::Monthly, $batch->id],
        ])
        ->and([$levy1->unit_id, $levy1->amount_kobo, $levy1->cycle, $levy1->effective_from->toDateString(), $levy1->next_billing_date?->toDateString(), $levy1->import_batch_id])
        ->toBe([$adaezeLease->unit_id, null, null, '2026-09-01', '2026-11-01', $batch->id])
        ->and([$levy2->unit_id, $levy2->amount_kobo?->kobo, $levy2->effective_from->toDateString(), $levy2->next_billing_date?->toDateString()])
        ->toBe([$bayoLease->unit_id, 1_200_000, '2026-10-28', '2026-10-28'])
        ->and([$power->starting_reading?->input(), $power->effective_from->toDateString(), $power->next_billing_date?->toDateString(), $power->amount_kobo])
        ->toBe(['1234.5', '2026-10-28', '2026-10-28', null]);

    // The control: the same past start added on the page, without a next billing date, catches up (P03-06b).
    $waste = utilityTypeIn($this->acme, $this->owner, ['name' => 'Waste', 'code' => 'WASTE', 'amount' => '5,000']);
    $pageAdded = utilityOn($this->acme, $this->owner, unitsOf($this->acme)->firstWhere('unit_label', 'Flat 1'), $waste, ['effectiveFrom' => '2026-09-01']);

    // The bill run on the import day (lead 7 days).
    runBillGeneration();

    expect(utilityBilledPeriods($this->acme, $levy1))->toBe([['2026-11-01', '2026-11-30', '2026-11-01', 1_000_000, $adaezeLease->tenant_profile_id]])
        ->and(utilityBilledPeriods($this->acme, $levy2))->toBe([['2026-10-28', '2026-11-27', '2026-10-28', 1_200_000, $bayoLease->tenant_profile_id]])
        ->and(utilityBilledPeriods($this->acme, $power))->toBe([])
        ->and(array_column(utilityBilledPeriods($this->acme, $pageAdded), 0))->toBe(['2026-09-01', '2026-10-01', '2026-11-01'])
        // No imported setting was billed for a period that started before the import day.
        ->and(utilityBillsOf($this->acme)->whereIn('unit_utility_setting_id', [$levy1->id, $levy2->id, $power->id])->filter(fn ($bill) => $bill->period_start->lessThan('2026-10-28')))->toHaveCount(0);

    // The metered utility bills the consumption after the last reading once its first period has ended.
    onUtilityBillingDay('2026-11-27');
    readMeter($this->acme, $this->owner, $power, '2026-11-27', '1334.5');
    runBillGeneration();
    expect(utilityBilledPeriods($this->acme, $power))->toBe([]);

    onUtilityBillingDay('2026-11-28');
    runBillGeneration();
    $bill = utilityBillsOf($this->acme, $power)->sole();

    expect([$bill->period_start->toDateString(), $bill->period_end->toDateString(), $bill->amount_kobo->kobo, $bill->tenant_profile_id])
        ->toBe(['2026-10-28', '2026-11-27', 2_500_000, $bayoLease->tenant_profile_id]) // (1,334.5 − 1,234.5) kWh × ₦250
        ->and(billLinesOf($this->acme, $bill)->sole()->meta['previous'] ?? null)->toBe('1234.500')
        ->and(array_column(utilityBilledPeriods($this->acme, $levy1), 0))->toBe(['2026-11-01', '2026-12-01']);

    Notification::assertNothingSent();
    Mail::assertNothingOutgoing();
});

it('imports the same utilities again without adding them twice, and refuses new terms once one is billed', function () {
    portfolioImport($this->acme, $this->owner, balancesWorkbook(utilities: standardUtilityRows()));

    $batch = portfolioDryRun($this->acme, $this->owner, balancesWorkbook(utilities: standardUtilityRows()));
    expect(portfolioCounts($batch, 'utilities'))->toMatchArray(['create' => 0, 'update' => 3, 'restore' => 0, 'skip' => 0])
        ->and(collect(sheetIssues($batch, 'utilities'))->where(3, 'error')->all())->toBe([]);
    $batch = portfolioCommit($this->acme, $this->owner, $batch);

    expect($batch->result_counts['targets']['utilities'])->toMatchArray(['update' => 3])
        ->and(settingsOf($this->acme))->toHaveCount(3)
        ->and(inOrganization($this->acme, fn () => UtilityType::query()->count()))->toBe(2);

    // Billed: the same row still passes, a new amount is refused.
    runBillGeneration();
    $rows = standardUtilityRows();
    $rows[1][5] = '15,000';
    $batch = portfolioDryRun($this->acme, $this->owner, balancesWorkbook(utilities: $rows));

    expect(sheetIssues($batch, 'utilities'))->toContain(
        [3, null, null, 'error', 'Security levy of Flat 2, Palm Court has bills or meter readings already, so an import cannot change it. Change it on its page.'],
    );
});

it('reports every bad cell of the Utilities sheet with its exact row, column and message', function () {
    $electricity = electricityIn($this->acme, $this->owner);
    $water = utilityTypeIn($this->acme, $this->owner, ['name' => 'Water', 'code' => 'WATER']);
    inOrganization($this->acme, fn () => app(UtilityTypes::class)->archive($this->owner, $water));

    $batch = portfolioDryRun($this->acme, $this->owner, balancesWorkbook(utilities: [
        utilityRow(['Building' => 'Palm Court', 'Unit' => 'Flat 9', 'Utility' => 'ELEC', 'Last meter reading' => '1']),                                    // 2
        utilityRow(['Building' => 'Palm Court', 'Unit' => 'Flat 1']),                                                                                     // 3
        utilityRow(['Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Utility' => 'Gas']),                                                                 // 4
        utilityRow(['Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Utility' => 'Gas', 'Metered' => 'Yes', 'Amount' => '300', 'Billing cycle' => 'Monthly', 'Last meter reading' => '5']), // 5
        utilityRow(['Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Utility' => 'ELEC', 'Metered' => 'No']),                                             // 6
        utilityRow(['Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Utility' => 'Electricity']),                                                         // 7
        utilityRow(['Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Utility' => 'Electricity', 'Last meter reading' => 'about 10']),                     // 8
        utilityRow(['Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Utility' => 'Water']),                                                               // 9
        utilityRow(['Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Utility' => 'Levy', 'Amount' => '-5', 'Billing cycle' => 'Fortnightly', 'Metered' => 'Maybe']), // 10
        utilityRow(['Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Utility' => 'Levy', 'Amount' => '5,000', 'Billing cycle' => 'Monthly', 'Paid until' => '32/01/2026']), // 11
        utilityRow(['Your ID' => 'UT-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 2', 'Utility' => 'Electricity', 'Last meter reading' => '10']),      // 12: fine
        utilityRow(['Your ID' => 'UT-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 3', 'Utility' => 'Electricity', 'Last meter reading' => '10']),      // 13
        utilityRow(['Building' => 'palm court', 'Unit' => 'flat 2', 'Utility' => 'ELEC', 'Last meter reading' => '10']),                                  // 14
        utilityRow(['Building' => 'Palm Court', 'Unit' => 'Flat 3', 'Utility' => 'Bin', 'Amount' => '2,000', 'Billing cycle' => 'Monthly', 'Metered' => 'Yes', 'Unit of measure' => 'Buckets']), // 15
        utilityRow(['Building' => 'Palm Court', 'Unit' => 'Flat 3', 'Utility' => 'Security', 'Amount' => '2,000', 'Billing cycle' => 'Monthly']),         // 16: fine (new)
        utilityRow(['Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Utility' => 'Security', 'Metered' => 'Yes', 'Last meter reading' => '3']),           // 17
    ]));

    expect($batch->status)->toBe(ImportStatus::DryRunFailed)
        ->and(sheetIssues($batch, 'utilities'))->toBe([
            [2, 'Unit', 'C', 'error', 'No unit is called "Flat 9" in Palm Court, here or in the Units sheet. Add it to the Units sheet first.'],
            [3, 'Utility', 'D', 'error', 'Enter the utility, such as Security levy or Electricity.'],
            [4, 'Amount', 'F', 'error', 'Gas is new: enter its amount (the rate per unit when metered).'],
            [4, 'Billing cycle', 'G', 'error', 'Gas is new: choose how often it is billed.'],
            [5, 'Unit of measure', 'H', 'error', 'Gas is new and metered: choose what its meter counts.'],
            [6, 'Metered', 'E', 'error', 'Electricity is metered on GoProperty. Leave Metered empty or put Yes.'],
            [7, 'Last meter reading', 'I', 'error', 'Enter the meter\'s last reading: consumption after it is billed.'],
            [8, 'Last meter reading', 'I', 'error', 'Enter the meter reading as it shows, such as 12345.6 (up to three decimals).'],
            [9, 'Utility', 'D', 'error', 'Water is archived. Restore it on the Utilities page before importing it.'],
            [10, 'Metered', 'E', 'error', 'Enter Yes or No.'],
            [10, 'Amount', 'F', 'error', 'Enter an amount above ₦0.'],
            [10, 'Billing cycle', 'G', 'error', 'Choose one of: Daily, Weekly, Monthly, Quarterly, Every six months, Yearly, Every two years.'],
            [11, 'Paid until', 'J', 'error', '"32/01/2026" is not a real date. Enter it as DD/MM/YYYY.'],
            [13, 'Your ID', 'A', 'error', 'This ID is already on row 12. Each unit\'s utility goes on one row.'],
            [14, 'Utility', 'D', 'error', 'Flat 2, Palm Court already has ELEC on row 12. Each unit pays a utility once.'],
            [15, 'Unit of measure', 'H', 'error', 'Choose one of: kWh, Cubic metres, Litres, Units.'],
            [16, 'Utility', 'D', 'warning', 'Adds the utility Security (flat, ₦2,000 monthly) to your utilities.'],
            [17, 'Metered', 'E', 'error', 'Row 16 adds Security as flat. Use the same on every row.'],
        ])
        ->and(settingsOf($this->acme))->toHaveCount(0)
        ->and($electricity->isMetered())->toBeTrue();
});
