<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Events\BillOverdue;
use Modules\Import\Enums\ImportFileType;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Reading\SpreadsheetFile;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Events\LeaseActivated;
use Modules\Leasing\Events\LeaseEndingSoon;
use Modules\Leasing\Events\LeaseImported;
use Modules\Leasing\Import\ImportedLease;
use Modules\Leasing\Import\ImportedLeaseStatus;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Models\LeaseContract;
use Modules\Properties\Enums\UnitStatus;

require_once __DIR__.'/helpers.php';

/*
 * P03-13a: the Leases import sheet (DATA_IMPORT.md § 1 row 5, § 3) through
 * the real engine. Ongoing rents come in active without back-billing
 * ("paid until" sets the next billing date, never a past period), past
 * leases as history, nobody is contacted and no contract is issued on
 * their own; exact per-row problems in the dry run.
 */

beforeEach(function () {
    Storage::fake('media');
    holdInvoicePdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-10-28 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
});

it('acceptance: imports a lease that started in 2023, paid until 31/12/2026, with no past bills; its first bill is the period from 01/01/2027', function () {
    Notification::fake();
    Mail::fake();
    Event::fake([LeaseActivated::class, LeaseEndingSoon::class, BillOverdue::class]);

    $batch = portfolioDryRun($this->acme, $this->owner, ongoingRentsWorkbook());

    expect($batch->status)->toBe(ImportStatus::DryRunPassed)
        ->and(portfolioCounts($batch, 'leases'))->toMatchArray(['create' => 3, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and(leasesOf($this->acme))->toHaveCount(0);

    $batch = portfolioCommit($this->acme, $this->owner, $batch);
    [$yearly, $monthly, $past] = leasesOf($this->acme)->all();

    // Active at once, billed from the period after "paid until", the deposit recorded, the unit let.
    expect($batch->status)->toBe(ImportStatus::Committed)
        ->and($batch->result_counts['targets']['leases'])->toMatchArray(['create' => 3, 'skip' => 0])
        ->and($yearly->status)->toBe(LeaseStatus::Active)
        ->and($yearly->external_ref)->toBe('L-1')
        ->and($yearly->start_date->toDateString())->toBe('2023-01-01')
        ->and($yearly->paid_until?->toDateString())->toBe('2026-12-31')
        ->and($yearly->next_billing_date?->toDateString())->toBe('2027-01-01')
        ->and($yearly->rent_amount_kobo->kobo)->toBe(150_000_000)
        ->and($yearly->import_batch_id)->toBe($batch->id)
        ->and($yearly->contract_number)->toBe('LS-ADR-2026-000001')
        ->and(depositEntriesOf($this->acme, $yearly))->toBe([['held', 15_000_000]])
        ->and($monthly->status)->toBe(LeaseStatus::Active)
        ->and($monthly->billing_cycle)->toBe(BillingCycle::Monthly)
        ->and($monthly->next_billing_date?->toDateString())->toBe('2026-11-15')
        ->and($monthly->contract_number)->toBe('LS-ADR-2026-000002')
        ->and($past->status)->toBe(LeaseStatus::Expired)
        ->and($past->next_billing_date)->toBeNull()
        ->and($past->contract_number)->toBe('PC/2021/007')
        ->and(depositEntriesOf($this->acme, $past))->toBe([['held', 10_000_000]])
        ->and(unitsOf($this->acme)->pluck('status', 'unit_label')->all())->toBe(['Flat 1' => UnitStatus::Occupied, 'Flat 2' => UnitStatus::Occupied, 'Flat 3' => UnitStatus::Vacant])
        ->and(AuditEntry::query()->where('log_name', 'leases')->where('event', 'lease.imported')->count())->toBe(3);

    Event::assertNotDispatched(LeaseActivated::class);

    // No past bills: the job finds nothing on the day of the import, nor in the days after.
    runBillGeneration();
    runOverdueFlagging();
    expect(billsOf($this->acme))->toHaveCount(0);

    // The monthly lease's first bill: its period after 14/11/2026, in the lead window.
    $this->travelTo('2026-11-08 00:10:00');
    runBillGeneration();
    runOverdueFlagging();
    expect(billedPeriodsOf($this->acme, $monthly))->toBe([['2026-11-15', '2026-12-14', '2026-11-15']])
        ->and(billedPeriodsOf($this->acme, $yearly))->toBe([]);

    $this->travelTo('2026-12-08 00:10:00');
    runBillGeneration();
    $this->travelTo('2026-12-24 00:10:00');
    runBillGeneration();
    expect(billedPeriodsOf($this->acme, $yearly))->toBe([]);

    // The yearly lease's first bill covers the period from 01/01/2027, billed ahead of it.
    $this->travelTo('2026-12-25 00:10:00');
    runBillGeneration();

    expect(billedPeriodsOf($this->acme, $yearly))->toBe([['2027-01-01', '2027-12-31', '2027-01-01']])
        ->and(billedPeriodsOf($this->acme, $monthly))->toBe([['2026-11-15', '2026-12-14', '2026-11-15'], ['2026-12-15', '2027-01-14', '2026-12-15']])
        ->and(billedPeriodsOf($this->acme, $past))->toBe([])
        // Nothing for a period before the import, and no bill already past due when it was issued.
        ->and(billsOf($this->acme)->filter(fn ($bill) => $bill->period_start->lessThan('2026-10-28')))->toHaveCount(0)
        ->and(billsOf($this->acme)->filter(fn ($bill) => $bill->due_date->lessThan($bill->issued_at->timezone('Africa/Lagos')->startOfDay())))->toHaveCount(0)
        ->and(billsOf($this->acme)->pluck('status')->unique()->values()->all())->toBe([BillStatus::Unpaid]);

    // No reminders for past dates (until P05-01: nothing sent, nothing flagged), nobody contacted, no contract issued.
    Event::assertNotDispatched(BillOverdue::class);
    Event::assertNotDispatched(LeaseEndingSoon::class);
    Notification::assertNothingSent();
    Mail::assertNothingOutgoing();
    expect(leaseImportWhatsApp())->toHaveCount(0)
        ->and(inOrganization($this->acme, fn () => LeaseContract::query()->count()))->toBe(0);
});

it('lets the unit through LeaseImported, not an activation, and never issues a contract on its own', function () {
    Event::fake([LeaseImported::class]);
    // Faking the event stops the unit listener too: the event must carry what the unit becomes.
    portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook());

    $leases = leasesOf($this->acme);
    Event::assertDispatchedTimes(LeaseImported::class, 3);
    Event::assertDispatched(LeaseImported::class, fn (LeaseImported $event): bool => $event->leaseId === $leases[0]->id && $event->active && $event->unitStatus === 'occupied');
    Event::assertDispatched(LeaseImported::class, fn (LeaseImported $event): bool => $event->leaseId === $leases[2]->id && ! $event->active && $event->unitStatus === null);

    expect(inOrganization($this->acme, fn () => LeaseContract::query()->count()))->toBe(0)
        ->and(leasesOf($this->acme)->pluck('activated_at')->filter()->count())->toBe(3);
});

it('reports every bad cell of the Leases sheet with its exact row, column and message', function () {
    $valid = ['Building' => 'Palm Court', 'Unit' => 'Flat 2', 'Tenant' => 'T-2', 'Start date' => '01/01/2024', 'End date' => '31/12/2027', 'Billing cycle' => 'Yearly', 'Rent per period' => '1,500,000', 'Paid until' => '31/12/2026'];
    $rows = [
        leaseRow(['Your ID' => 'L-1', 'Tenant' => 'T-1', 'Unit' => 'Flat 1'] + $valid),                                          // 2: fine
        leaseRow(['Tenant' => ''] + $valid),                                                                                    // 3
        leaseRow(['Tenant' => '0809 000 0000'] + $valid),                                                                       // 4
        leaseRow(['Building' => 'Oak Lodge'] + $valid),                                                                          // 5
        leaseRow(['Unit' => 'Flat 9'] + $valid),                                                                                 // 6
        leaseRow(['Start date' => '31/02/2023'] + $valid),                                                                       // 7
        leaseRow(['Start date' => '01/01/2024', 'End date' => '01/01/2023', 'Paid until' => ''] + $valid),                      // 8
        leaseRow(['Billing cycle' => 'Fortnightly'] + $valid),                                                                   // 9
        leaseRow(['Rent per period' => '0', 'Deposit held' => '-5,000'] + $valid),                                               // 10
        leaseRow(['Start date' => '01/03/2023', 'Paid until' => '28/02/2023'] + $valid),                                         // 11
        leaseRow(['Billing cycle' => 'Weekly', 'Rent per period' => '30,000', 'Due days after period start' => '9'] + $valid),   // 12
        leaseRow(['Auto-renew' => 'Maybe'] + $valid),                                                                            // 13
        leaseRow(['Status' => 'Active', 'End date' => '31/12/2025', 'Paid until' => '31/12/2025'] + $valid),                    // 14
        leaseRow(['Status' => 'Month-to-month', 'End date' => '31/12/2025', 'Paid until' => '31/12/2025'] + $valid),            // 15
        leaseRow(['Your ID' => 'L-1'] + $valid),                                                                                 // 16
        leaseRow(['Unit' => 'Flat 1'] + $valid),                                                                                 // 17
        leaseRow(['Contract number' => 'LS-ADR-2026-000009'] + $valid),                                                          // 18
        leaseRow(['Status' => 'Expired'] + $valid),                                                                              // 19
        leaseRow(['Notes' => str_repeat('x', 2001)] + $valid),                                                                   // 20
    ];

    $batch = portfolioDryRun($this->acme, $this->owner, ongoingRentsWorkbook($rows));

    expect(array_values(array_filter(leaseIssues($batch), fn (array $issue): bool => $issue[3] === 'error')))->toBe([
        [3, 'Tenant', 'B', 'error', 'Enter the tenant\'s phone number or Your ID from the Tenants sheet.'],
        [4, 'Tenant', 'B', 'error', 'No tenant has the phone number or ID "0809 000 0000", here or in the Tenants sheet. Add them to the Tenants sheet first.'],
        [5, 'Building', 'C', 'error', 'No building is called "Oak Lodge", here or in the Buildings sheet. Add it to the Buildings sheet first.'],
        [6, 'Unit', 'D', 'error', 'No unit is called "Flat 9" in Palm Court, here or in the Units sheet. Add it to the Units sheet first.'],
        [7, 'Start date', 'E', 'error', '"31/02/2023" is not a real date. Enter it as DD/MM/YYYY.'],
        [8, 'End date', 'F', 'error', 'The lease must end after it starts (01/01/2024).'],
        [9, 'Billing cycle', 'G', 'error', 'Choose one of: Daily, Weekly, Monthly, Quarterly, Every six months, Yearly, Every two years.'],
        [10, 'Deposit held', 'I', 'error', 'The deposit cannot be negative.'],
        [10, 'Rent per period', 'H', 'error', 'Enter a rent above ₦0.'],
        [11, 'Paid until', 'J', 'error', 'Paid until must be on or after the start date (01/03/2023). Leave it empty when nothing has been paid.'],
        [12, 'Due days after period start', 'K', 'error', 'Enter a whole number from 0 to 6: the rent must fall due within its period.'],
        [13, 'Auto-renew', 'L', 'error', 'Enter Yes or No.'],
        [14, 'Status', 'N', 'error', 'This lease ended on 31/12/2025. Leave Status empty or choose Expired to import it as history, or Month-to-month if the tenant stays and pays monthly.'],
        [15, 'Billing cycle', 'G', 'error', 'A month-to-month lease is billed monthly: set Billing cycle to Monthly and the rent to one month\'s rent.'],
        [16, 'Your ID', 'A', 'error', 'This ID is already on row 2. Each lease goes on one row.'],
        [17, 'Unit', 'D', 'error', 'Flat 1, Palm Court already has an ongoing lease on row 2. A unit has one active lease: import the older one as expired.'],
        [18, 'Contract number', 'M', 'error', 'LS-ADR-2026-000009 looks like one of GoProperty\'s own numbers (LS-ADR-…), which it gives to new leases. Leave it empty and GoProperty numbers the lease, or use your own numbering.'],
        [19, 'Status', 'N', 'error', 'This lease runs until 31/12/2027, so it has not expired. Leave Status empty to import it as active.'],
        [20, 'Notes', 'O', 'error', 'Use at most 2000 characters.'],
    ])
        ->and($batch->status)->toBe(ImportStatus::DryRunFailed)
        ->and(leasesOf($this->acme))->toHaveCount(0);
});

it('warns about what the agency should know: rent unlike the unit\'s, a tenant with another lease, past periods not billed, history and month-to-month', function () {
    $rows = [
        leaseRow(['Your ID' => 'L-1', 'Tenant' => 'T-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Start date' => '01/01/2023', 'End date' => '31/12/2027', 'Billing cycle' => 'Yearly', 'Rent per period' => '1,650,000', 'Paid until' => '31/12/2025']),
        leaseRow(['Your ID' => 'L-2', 'Tenant' => '0803 555 0101', 'Building' => 'Palm Court', 'Unit' => 'Flat 2', 'Start date' => '15/03/2023', 'End date' => '14/03/2028', 'Billing cycle' => 'Monthly', 'Rent per period' => '125,000', 'Paid until' => '14/11/2026']),
        leaseRow(['Your ID' => 'L-3', 'Tenant' => 'T-3', 'Building' => 'Palm Court', 'Unit' => 'Flat 3', 'Start date' => '01/03/2024', 'End date' => '28/02/2025', 'Billing cycle' => 'Monthly', 'Rent per period' => '150,000', 'Paid until' => '31/10/2026', 'Status' => 'Month-to-month']),
        leaseRow(['Your ID' => 'L-4', 'Tenant' => 'T-2', 'Building' => 'Palm Court', 'Unit' => 'Flat 3', 'Start date' => '01/02/2021', 'End date' => '31/01/2023', 'Billing cycle' => 'Yearly', 'Rent per period' => '1,200,000']),
    ];

    // The units and tenants are on file first: the unit's asking rent is compared with the lease's.
    portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook(sheets: ['Leases' => null]));
    $batch = portfolioDryRun($this->acme, $this->owner, ongoingRentsWorkbook($rows, ['Buildings' => null, 'Units' => null, 'Tenants' => null]));

    expect(leaseIssues($batch))->toBe([
        [2, 'Rent per period', 'H', 'warning', 'The unit\'s asking rent is ₦1,500,000 a year; this lease\'s rent comes to ₦1,650,000 a year. The lease keeps its own rent.'],
        [2, 'Paid until', 'J', 'warning', '01/01/2026 to 31/12/2026 is not paid, by Paid until, and GoProperty never bills the past: put what the tenant owes for it on the Opening balances sheet. Billing starts on 01/01/2027.'],
        [3, 'Tenant', 'B', 'warning', 'Adaeze Okafor already has an ongoing lease on row 2. This one is added next to it.'],
        [4, 'Status', 'N', 'warning', 'This lease ended on 28/02/2025. It is imported as an active monthly lease, renewing on its own, until 30/09/2027.'],
        [5, 'End date', 'F', 'warning', 'This lease ended on 31/01/2023, so it is imported as expired (history): it is not billed and does not let the unit.'],
    ])
        ->and($batch->status)->toBe(ImportStatus::DryRunPassed);

    portfolioCommit($this->acme, $this->owner, $batch);
    [$yearly, , $monthToMonth, $past] = leasesOf($this->acme)->all();

    // Past periods are never billed: billing starts with the first period from today.
    expect($yearly->next_billing_date?->toDateString())->toBe('2027-01-01')
        ->and($yearly->paid_until?->toDateString())->toBe('2025-12-31')
        ->and($monthToMonth->status)->toBe(LeaseStatus::Active)
        ->and($monthToMonth->end_date->toDateString())->toBe('2027-09-30')
        ->and($monthToMonth->auto_renew)->toBeTrue()
        ->and($monthToMonth->next_billing_date?->toDateString())->toBe('2026-11-01')
        ->and($past->status)->toBe(LeaseStatus::Expired)
        ->and(unitsOf($this->acme)->pluck('status', 'unit_label')->all())->toBe(['Flat 1' => UnitStatus::Occupied, 'Flat 2' => UnitStatus::Occupied, 'Flat 3' => UnitStatus::Occupied]);

    // On the next import, the tenant's active lease on file is the warning.
    $again = portfolioDryRun($this->acme, $this->owner, ongoingRentsWorkbook([
        leaseRow(['Tenant' => 'T-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 4', 'Start date' => '01/06/2026', 'End date' => '31/05/2027', 'Billing cycle' => 'Yearly', 'Rent per period' => '1,800,000', 'Paid until' => '31/05/2027']),
    ], ['Units' => [PORTFOLIO_UNITS, ['U-4', 'Palm Court', 'Flat 4', 'Flat', '3', '2', '', '2nd', '₦1,800,000', '150,000', '', '']]]));

    expect(leaseIssues($again))->toBe([
        [2, 'Tenant', 'B', 'warning', 'Adaeze Okafor already has an active lease (LS-ADR-2026-000001). This one is added next to it.'],
    ]);
});

it('keeps a contract number the agency gives, numbers the others, and refuses one that is already another lease\'s', function () {
    $rows = standardLeaseRows();
    $rows[0][12] = 'PC/2023/001';
    portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook($rows));

    expect(leasesOf($this->acme)->pluck('contract_number')->all())->toBe(['PC/2023/001', 'LS-ADR-2026-000001', 'PC/2021/007']);

    $clash = portfolioDryRun($this->acme, $this->owner, ongoingRentsWorkbook([
        leaseRow(['Tenant' => 'T-3', 'Building' => 'Palm Court', 'Unit' => 'Flat 3', 'Start date' => '01/06/2026', 'End date' => '31/05/2027', 'Billing cycle' => 'Yearly', 'Rent per period' => '1,800,000', 'Paid until' => '31/05/2027', 'Contract number' => 'PC/2023/001']),
        leaseRow(['Tenant' => 'T-3', 'Building' => 'Palm Court', 'Unit' => 'Flat 2', 'Start date' => '01/06/2019', 'End date' => '31/05/2020', 'Billing cycle' => 'Yearly', 'Rent per period' => '900,000', 'Contract number' => 'PC/2019/004']),
        leaseRow(['Tenant' => 'T-2', 'Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Start date' => '01/06/2018', 'End date' => '31/05/2019', 'Billing cycle' => 'Yearly', 'Rent per period' => '900,000', 'Contract number' => 'PC/2019/004']),
    ]));

    expect(array_values(array_filter(leaseIssues($clash), fn (array $issue): bool => $issue[3] === 'error')))->toBe([
        [2, 'Contract number', 'M', 'error', 'Contract number PC/2023/001 is already another lease\'s.'],
        [4, 'Contract number', 'M', 'error', 'Contract number PC/2019/004 is already on row 3. Each lease needs its own number.'],
    ]);
});

it('adds a Leases sheet to the template, whose example lease lets the example unit to the example tenant', function () {
    leasesFreshRequest();
    $response = $this->actingAs($this->owner)->get('/o/acme-homes/imports/template')->assertOk();
    $path = (string) tempnam(sys_get_temp_dir(), 'gp-template-');
    copy($response->baseResponse->getFile()->getPathname(), $path);
    $file = SpreadsheetFile::open($path, ImportFileType::Xlsx);
    $leases = array_values(iterator_to_array($file->rows(5), false));

    expect(array_column($file->sheets(), 'name'))->toContain('Leases')
        ->and($leases[0])->toBe(LEASES_HEADER)
        ->and($leases[1][1])->toBe('0803 123 4567')
        ->and(array_slice($leases[1], 2, 2))->toBe(['Palm Court', 'Flat 1']);

    $batch = portfolioImport($this->acme, $this->owner, new UploadedFile($path, 'template.xlsx', null, null, true));
    $lease = leasesOf($this->acme)->sole();

    expect($batch->result_counts['targets']['leases'])->toMatchArray(['create' => 1, 'skip' => 0])
        ->and($lease->status)->toBe(LeaseStatus::Active)
        ->and($lease->external_ref)->toBe('L-001')
        ->and($lease->paid_until?->toDateString())->toBe('2026-12-31')
        ->and($lease->next_billing_date?->toDateString())->toBe('2027-01-01');
});

it('adds no lease for a restricted organization (ONBOARDING_COMPLIANCE.md § 5.5), but still updates the ones on file', function () {
    portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook([standardLeaseRows()[0]]));
    $this->acme->forceFill(['status' => OrganizationStatus::Restricted])->save();

    $rows = standardLeaseRows();
    $rows[0][7] = '1,600,000';
    $batch = portfolioDryRun($this->acme, $this->owner, ongoingRentsWorkbook($rows, ['Buildings' => null, 'Units' => null, 'Tenants' => null]));
    $restricted = 'Your organization is restricted until its Transaction Tier is upgraded, so it cannot add leases. Leases already on GoProperty can still be updated.';

    expect(array_values(array_filter(leaseIssues($batch), fn (array $issue): bool => $issue[3] === 'error')))->toBe([
        [3, null, null, 'error', $restricted],
        [4, null, null, 'error', $restricted],
    ]);

    // The service refuses too, past the dry run.
    $flat = unitsOf($this->acme)->firstWhere('unit_label', 'Flat 2');
    $bayo = tenantsOf($this->acme)->firstWhere('first_name', 'Bayo');
    $data = new ImportedLease($bayo->id, $flat->id, ImportedLeaseStatus::Active, BillingCycle::Annual, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-12-31'), Money::naira(1_500_000), Money::zero(), 0, CarbonImmutable::parse('2026-12-31'), false, null, null, null);

    expect(fn () => inOrganization($this->acme, fn () => app(LeaseService::class)->importOngoing(1, $data)))->toThrow(ValidationException::class, $restricted);
    expect(leasesOf($this->acme))->toHaveCount(1)
        ->and(leasesOf($this->acme)->sole()->rent_amount_kobo->kobo)->toBe(150_000_000);
});
