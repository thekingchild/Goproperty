<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityType;
use Modules\Import\Batches\ImportRefused;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Models\ImportBatch;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\TenantCreditBalance;
use Modules\Tenants\Models\TenantProfile;

require_once __DIR__.'/helpers.php';

/*
 * P03-13b: the Opening balances, Utilities and Payment history sheets
 * together: the dry run's counts are what the commit does (first import,
 * re-import, after a rollback), and the rollback (DATA_IMPORT.md § 2
 * "Undo") takes their records back while unused and is refused once one
 * is used.
 */

beforeEach(function () {
    Storage::fake('media');
    holdInvoicePdfs();
    holdReceiptPdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-10-28 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com']), $this->acme);
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
});

/** The full migration file: the standard ongoing rents plus balances (two owed, one credit), utilities and history (no receipt asked unless `$receipt`). */
function fullMigrationWorkbook(bool $receipt = false, bool $utilities = true): UploadedFile
{
    return balancesWorkbook(
        balances: [
            balanceRow(['Your ID' => 'OB-1', 'Tenant' => '0803 555 0102', 'Building' => 'Palm Court', 'Unit' => 'Flat 2', 'Amount owed' => '300,000', 'As-of date' => '30/09/2026']),
            balanceRow(['Tenant' => 'T-1', 'Amount owed' => '-100,000', 'As-of date' => '27/10/2026']),
            balanceRow(['Tenant' => 'T-3', 'Building' => 'Palm Court', 'Unit' => 'Flat 3', 'Amount owed' => '80,000', 'As-of date' => '31/01/2023', 'Description' => 'Unpaid when the lease ended']),
        ],
        utilities: ! $utilities ? null : [
            utilityRow(['Your ID' => 'UT-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Utility' => 'Security levy', 'Amount' => '10,000', 'Billing cycle' => 'Monthly', 'Paid until' => '31/08/2026']),
            utilityRow(['Your ID' => 'UT-2', 'Building' => 'Palm Court', 'Unit' => 'Flat 2', 'Utility' => 'Security levy', 'Amount' => '12,000']),
            utilityRow(['Your ID' => 'UT-3', 'Building' => 'Palm Court', 'Unit' => 'Flat 2', 'Utility' => 'Electricity', 'Metered' => 'Yes', 'Amount' => '250', 'Billing cycle' => 'Monthly', 'Unit of measure' => 'kWh', 'Last meter reading' => '1234.5']),
        ],
        history: [
            historyRow(['Your ID' => 'P-1', 'Tenant' => 'T-1', 'Date paid' => '15/12/2025', 'Amount' => '1,500,000', 'Method' => 'Bank transfer']),
            historyRow(['Tenant' => '0803 555 0102', 'Date paid' => '14/09/2026', 'Amount' => '125,000', 'Method' => 'Cash']),
            historyRow(['Tenant' => 'T-2', 'Date paid' => '14/10/2026', 'Amount' => '125,000', 'Method' => 'Cash', 'Issue receipt' => $receipt ? 'Yes' : 'No']),
        ],
    );
}

/** What the database holds for these sheets now (archived and switched-off records apart). */
function migrationRecords(Organization $organization): array
{
    return inOrganization($organization, fn (): array => [
        'opening_balances' => openingBalancesOf($organization)->filter(fn ($balance) => ! $balance->trashed())->count(),
        'opening_balance_bills' => Bill::query()->where('type', BillType::OpeningBalance)->count(),
        'utility_settings_on' => UnitUtilitySetting::query()->where('active', true)->count(),
        'utility_types_active' => UtilityType::query()->where('active', true)->count(),
        'history_payments' => Payment::query()->where('source', 'import')->count(),
        'leases' => leasesOf($organization)->reject->trashed()->count(),
    ]);
}

/** The dry run's counts and the commit's, sheet by sheet. */
function countsOfEachSheet(ImportBatch $dryRun, ImportBatch $committed): array
{
    $planned = collect($dryRun->dry_run_report['sheets'])->mapWithKeys(fn (array $sheet): array => [$sheet['target'] => $sheet['counts']])->all();

    return [$planned, $committed->result_counts['targets']];
}

it('acceptance: dry-run counts are exact: what the dry run counts per sheet is what the commit does, first, again, and after a rollback', function () {
    // First import: everything is new.
    $dryRun = portfolioDryRun($this->acme, $this->owner, fullMigrationWorkbook());
    $first = portfolioCommit($this->acme, $this->owner, $dryRun);
    [$planned, $done] = countsOfEachSheet($dryRun, $first);

    expect($dryRun->status)->toBe(ImportStatus::DryRunPassed)
        ->and($planned['opening_balances'])->toEqual(['create' => 3, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and($planned['utilities'])->toEqual(['create' => 3, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and($planned['payment_history'])->toEqual(['create' => 3, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and($done)->toEqual($planned)
        ->and($dryRun->dry_run_report['counts'])->toEqual(['create' => 19, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and(migrationRecords($this->acme))->toEqual(['opening_balances' => 3, 'opening_balance_bills' => 2, 'utility_settings_on' => 3, 'utility_types_active' => 2, 'history_payments' => 3, 'leases' => 3]);

    // The same file again: every row updates, nothing is added.
    $dryRun = portfolioDryRun($this->acme, $this->owner, fullMigrationWorkbook());
    $again = portfolioCommit($this->acme, $this->owner, $dryRun);
    [$planned, $done] = countsOfEachSheet($dryRun, $again);

    expect($planned['opening_balances'])->toEqual(['create' => 0, 'update' => 3, 'restore' => 0, 'skip' => 0])
        ->and($planned['utilities'])->toEqual(['create' => 0, 'update' => 3, 'restore' => 0, 'skip' => 0])
        ->and($planned['payment_history'])->toEqual(['create' => 0, 'update' => 3, 'restore' => 0, 'skip' => 0])
        ->and($done)->toEqual($planned)
        ->and(migrationRecords($this->acme))->toEqual(['opening_balances' => 3, 'opening_balance_bills' => 2, 'utility_settings_on' => 3, 'utility_types_active' => 2, 'history_payments' => 3, 'leases' => 3]);

    // Rolled back, then the same file once more: every row restores what the rollback took.
    portfolioRollback($this->acme, $this->owner, $first);
    expect(migrationRecords($this->acme))->toEqual(['opening_balances' => 0, 'opening_balance_bills' => 0, 'utility_settings_on' => 0, 'utility_types_active' => 0, 'history_payments' => 0, 'leases' => 0]);

    $dryRun = portfolioDryRun($this->acme, $this->owner, fullMigrationWorkbook());
    $restored = portfolioCommit($this->acme, $this->owner, $dryRun);
    [$planned, $done] = countsOfEachSheet($dryRun, $restored);

    expect($dryRun->status)->toBe(ImportStatus::DryRunPassed)
        ->and($planned['opening_balances'])->toEqual(['create' => 0, 'update' => 0, 'restore' => 3, 'skip' => 0])
        ->and($planned['utilities'])->toEqual(['create' => 0, 'update' => 0, 'restore' => 3, 'skip' => 0])
        ->and($planned['payment_history'])->toEqual(['create' => 0, 'update' => 0, 'restore' => 3, 'skip' => 0])
        ->and($done)->toEqual($planned)
        ->and(migrationRecords($this->acme))->toEqual(['opening_balances' => 3, 'opening_balance_bills' => 2, 'utility_settings_on' => 3, 'utility_types_active' => 2, 'history_payments' => 3, 'leases' => 3]);
});

it('rolls back the balances, credits, utilities and history while unused, and importing again brings the same records back', function () {
    $batch = portfolioImport($this->acme, $this->owner, fullMigrationWorkbook());
    $bills = inOrganization($this->acme, fn () => Bill::query()->where('type', BillType::OpeningBalance)->orderBy('id')->get());
    $payments = paymentsOf($this->acme)->sortBy('id')->values();
    $adaeze = inOrganization($this->acme, fn () => TenantProfile::query()->where('phone', '+2348035550101')->sole());

    portfolioRollback($this->acme, $this->owner, $batch);

    // Bills taken back (their pay pages gone), the credit reversed by an entry, the history gone, utilities off, the types archived.
    billsFreshRequest();
    $this->get("/pay/{$bills[0]->payment_reference}")->assertNotFound();
    expect(inOrganization($this->acme, fn () => Bill::query()->withTrashed()->whereIn('id', $bills->pluck('id'))->get()->every->trashed()))->toBeTrue()
        ->and(creditLedgerOf($this->acme))->toBe([[$adaeze->id, 'opening_balance', 10_000_000], [$adaeze->id, 'import_rolled_back', -10_000_000]])
        ->and(inOrganization($this->acme, fn () => TenantCreditBalance::query()->where('tenant_profile_id', $adaeze->id)->sole()->balance_kobo->kobo))->toBe(0)
        ->and(inOrganization($this->acme, fn () => Payment::query()->onlyTrashed()->count()))->toBe(3)
        ->and(settingsOf($this->acme)->pluck('active')->unique()->all())->toBe([false])
        ->and(inOrganization($this->acme, fn () => UtilityType::query()->pluck('active')->unique()->all()))->toBe([false])
        ->and(openingBalancesOf($this->acme)->every->trashed())->toBeTrue()
        ->and(AuditEntry::query()->whereIn('event', ['bill.import_rolled_back', 'opening_balance.import_rolled_back', 'payment.import_rolled_back', 'utility_setting.import_rolled_back', 'utility_type.import_rolled_back'])->pluck('event')->countBy()->all())
        ->toEqual(['bill.import_rolled_back' => 2, 'opening_balance.import_rolled_back' => 3, 'payment.import_rolled_back' => 3, 'utility_setting.import_rolled_back' => 3, 'utility_type.import_rolled_back' => 2]);

    // Imported again: the same bills (numbers kept) and payments come back, the credit is added again.
    $dryRun = portfolioDryRun($this->acme, $this->owner, fullMigrationWorkbook());
    expect(collect($dryRun->dry_run_report['sheets'])->flatMap(fn (array $sheet) => $sheet['issues'])->where('severity', 'error')->values()->all())->toBe([]);
    $again = portfolioCommit($this->acme, $this->owner, $dryRun);
    $back = inOrganization($this->acme, fn () => Bill::query()->where('type', BillType::OpeningBalance)->orderBy('id')->get());

    expect($back->pluck('number')->all())->toBe($bills->pluck('number')->all())
        ->and($back->pluck('import_batch_id')->unique()->all())->toBe([$again->id])
        ->and(paymentsOf($this->acme)->sortBy('id')->pluck('id')->values()->all())->toBe($payments->pluck('id')->all())
        ->and(creditOf($this->acme, $adaeze)->kobo)->toBe(10_000_000)
        ->and(settingsOf($this->acme)->pluck('active')->unique()->all())->toBe([true])
        ->and(settingsOf($this->acme)->pluck('import_batch_id')->unique()->all())->toBe([$again->id])
        ->and(inOrganization($this->acme, fn () => UtilityType::query()->pluck('import_batch_id')->unique()->all()))->toBe([$again->id]);

    // And that import can be rolled back in turn.
    portfolioRollback($this->acme, $this->owner, $again);
    expect(creditOf($this->acme, $adaeze)->kobo)->toBe(0);
});

it('acceptance: refuses a rollback once an opening balance is paid, a credit used, a history receipt issued, or a utility billed or read', function (Closure $use, string $kind, bool $utilities = true) {
    $batch = portfolioImport($this->acme, $this->owner, fullMigrationWorkbook(receipt: $kind === 'payment history', utilities: $utilities));
    $use($this);

    expect(fn () => portfolioRollback($this->acme, $this->owner, $batch))
        ->toThrow(ImportRefused::class, "Some {$kind} from this import have been used since");
    expect(openingBalancesOf($this->acme)->filter->trashed())->toHaveCount(0)
        ->and(leasesOf($this->acme))->toHaveCount(3);
})->with([
    'an opening balance paid in part' => [function ($test): void {
        confirmedPayment($test->acme, $test->owner, inOrganization($test->acme, fn () => TenantProfile::query()->where('phone', '+2348035550102')->sole()), '50,000');
    }, 'opening balances'],
    'a payment recorded for an opening balance' => [function ($test): void {
        $bill = inOrganization($test->acme, fn () => Bill::query()->where('type', BillType::OpeningBalance)->orderBy('id')->first());
        recordPayment($test->acme, $test->owner, $bill->tenant_profile_id, '50,000', ['billId' => (string) $bill->id]);
    }, 'opening balances'],
    'an imported credit used for a bill' => [function ($test): void {
        setLeadDays($test->acme, 70); // Adaeze's yearly rent from 01/01/2027 is billed now, and takes her credit
        runBillGeneration();
        expect(creditOf($test->acme, inOrganization($test->acme, fn () => TenantProfile::query()->where('phone', '+2348035550101')->sole()))->kobo)->toBe(0);
    }, 'opening balances', false], // without utilities, which the bill run would bill too
    'a receipt issued for the history' => [fn () => null, 'payment history'],
    'a utility billed' => [function (): void {
        runBillGeneration();
    }, 'utilities'],
    'a meter read' => [function ($test): void {
        readMeter($test->acme, $test->owner, inOrganization($test->acme, fn () => UnitUtilitySetting::query()->where('external_ref', 'UT-3')->sole()), '2026-10-28', '1240');
    }, 'utilities'],
]);
