<?php

declare(strict_types=1);

namespace Modules\Payments\Import;

use App\Support\Money;
use Carbon\CarbonImmutable;
use LogicException;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Contracts\OpeningBalanceBill;
use Modules\Billing\Contracts\OpeningBalanceBills;
use Modules\Import\Contracts\ImportAction;
use Modules\Import\Contracts\ImportColumn;
use Modules\Import\Contracts\ImportRow;
use Modules\Import\Contracts\ImportRun;
use Modules\Import\Contracts\ImportTarget;
use Modules\Import\Contracts\InvalidCellValue;
use Modules\Import\Contracts\RowIssue;
use Modules\Import\Contracts\RowPlan;
use Modules\Import\Contracts\UpsertResult;
use Modules\Leasing\Contracts\LeaseSummary;
use Modules\Leasing\Contracts\TenantLeases;
use Modules\Payments\Credit\TenantCredit;
use Modules\Payments\Enums\CreditReason;
use Modules\Payments\Models\OpeningBalance;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\TenantCreditEntry;
use Modules\Payments\Payments\PaymentDates;
use Modules\Properties\Contracts\ImportedUnits;
use Modules\Properties\Contracts\UnitReference;
use Modules\Tenants\Contracts\ImportedTenants;
use Modules\Tenants\Contracts\TenantReference;
use RuntimeException;
use WeakMap;

/**
 * The Opening balances sheet (DATA_IMPORT.md § 1 row 6, § 3; P03-13b): what
 * each tenant owed, or had in credit, on the day the agency's old records
 * stop (the as-of date). One row per tenant (and unit): an amount owed
 * becomes ONE bill of type `opening_balance` through Billing's
 * Contracts\OpeningBalanceBills — due on the as-of date, so it is payable at
 * once and counts in arrears (overdue) without back-billing any period; a
 * negative amount (credit: the tenant paid ahead) becomes a tenant credit
 * entry (Credit\TenantCredit), applied automatically to the tenant's next
 * bill. Each is kept as an `opening_balances` row with the import's
 * provenance.
 *
 * Nothing is sent: the bill's BillIssued carries `imported`, so P05's
 * notices leave it alone, and the tenant hears nothing until "Invite
 * tenants now". A row matches an opening balance by Your ID, else by tenant
 * + unit + as-of date, rolled-back ones included: re-importing changes
 * nothing (an issued bill never changes) and brings a rolled-back one back.
 * Rollback withdraws the bills and reverses the credits (entries are never
 * deleted), and is refused once a bill was paid or voided or a credit used.
 *
 * Reads other modules through their contracts only: tenants (Tenants'
 * ImportedTenants), units (Properties' ImportedUnits), the tenant's lease
 * on the unit (Leasing's TenantLeases), bills (Billing's BillLedger and
 * OpeningBalanceBills).
 */
final class OpeningBalancesImportTarget implements ImportTarget
{
    public const string KEY = 'opening_balances';

    private const string ROWS_BY_REF = 'opening_balances:ref';

    private const string ROWS_BY_KEY = 'opening_balances:key';

    private const int MAX_NAIRA = 10_000_000_000;

    private const int MAX_DESCRIPTION = 255;

    /** @var WeakMap<ImportRun, array<string, TenantReference>> */
    private WeakMap $tenantRefs;

    /** @var WeakMap<ImportRun, array<string, UnitReference>> */
    private WeakMap $unitRefs;

    /**
     * Holds no collaborator: this target is a singleton, so whatever reads
     * the organization (its context is scoped) is resolved when used.
     */
    public function __construct()
    {
        $this->tenantRefs = new WeakMap;
        $this->unitRefs = new WeakMap;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Opening balances';
    }

    /** After the leases (a bill names the tenant's lease), before utilities and payment history (DATA_IMPORT.md § 2.4). */
    public function order(): int
    {
        return 60;
    }

    public function instructions(): string
    {
        return 'One row per tenant (and unit): what they owed on the as-of date, the day your old records stop. An amount owed becomes one "Opening balance" bill, due on that date: it can be paid and counts in arrears. A credit (the tenant paid ahead) as a negative amount, such as -50,000: it is taken off the tenant\'s next bill. Nobody is contacted.';
    }

    public function columns(): array
    {
        return [
            ImportColumn::externalRef('OB-001'),
            new ImportColumn('tenant', 'Tenant', required: true, aliases: ['tenant phone', 'tenant phone number', 'tenant id', 'tenant ref', 'phone', 'phone number', 'tenant mobile'], example: '0803 123 4567', help: 'The tenant\'s phone number, or Your ID from the Tenants sheet.'),
            new ImportColumn('building', 'Building', aliases: ['building name', 'property', 'property name', 'estate'], example: 'Palm Court', help: 'The unit\'s building (name or Your ID). Required for an amount owed: the bill is for the unit.'),
            new ImportColumn('unit_label', 'Unit', aliases: ['unit name', 'unit label', 'unit number', 'flat', 'flat number', 'apartment', 'shop number'], example: 'Flat 1', help: 'The unit\'s name in its building. Required for an amount owed.'),
            new ImportColumn('amount', 'Amount owed', required: true, aliases: ['balance', 'opening balance', 'amount', 'arrears', 'outstanding', 'owed', 'balance owed', 'amount due', 'balance due'], example: '₦250,000', help: 'What the tenant owed on the as-of date. A credit (paid ahead) as a negative amount, such as -50,000.'),
            new ImportColumn('as_of', 'As-of date', required: true, aliases: ['as of', 'as at', 'as-at date', 'as of date', 'balance date', 'date'], example: '30/09/2026', help: 'DD/MM/YYYY, today or earlier: the day the balance stood. An amount owed is due on it.'),
            new ImportColumn('description', 'Description', aliases: ['details', 'note', 'notes', 'narration', 'remarks', 'for'], example: 'Rent arrears from the old system', help: 'Printed on the bill. Empty: "Owed on" the as-of date.'),
        ];
    }

    public function validate(ImportRow $row, ImportRun $run): array
    {
        $issues = $this->refIssues($row, $run);
        $tenant = ($text = $row->text('tenant')) !== null ? $this->tenant($text, $run) : null;
        $unit = $this->unitOf($row, $run);

        if ($tenant === null) {
            $issues[] = RowIssue::error('tenant', __('Enter the tenant\'s phone number or Your ID from the Tenants sheet.'));
        } elseif ($tenant->error !== null) {
            $issues[] = RowIssue::error('tenant', $tenant->error);
        }

        if ($row->text('building') !== null && $row->text('unit_label') === null) {
            $issues[] = RowIssue::error('unit_label', __('Enter the unit\'s name, such as Flat 3B, or leave Building empty too.'));
        } elseif ($row->text('building') === null && $row->text('unit_label') !== null) {
            $issues[] = RowIssue::error('building', __('Enter the unit\'s building: its name or Your ID.'));
        } elseif ($unit?->error !== null) {
            $issues[] = RowIssue::error($unit->aboutBuilding ? 'building' : 'unit_label', $unit->error);
        }

        $amount = $this->amountCell($row, $issues);
        $asOf = $this->asOfCell($row, $issues);
        $issues = [...$issues, ...$this->descriptionIssues($row)];

        if (self::hasErrors($issues) || $tenant === null || $amount === null || $asOf === null) {
            return $issues;
        }

        if ($amount->isPositive() && $unit === null) {
            return [...$issues, RowIssue::error('building', __('An amount owed becomes a bill for the unit: enter its building and unit.'))];
        }

        $key = $this->naturalKey($tenant, $unit, $asOf);

        if (($earlier = $run->rowOf(self::ROWS_BY_KEY, $key)) !== null) {
            return [...$issues, RowIssue::error('as_of', __(':name already has an opening balance for this unit and date on row :row. Put it on one row.', ['name' => $tenant->name, 'row' => $earlier]))];
        }

        $run->remember(self::ROWS_BY_KEY, $key, $row->number);

        return [...$issues, ...$this->matchIssues($this->match($row, $tenant, $unit, $asOf), $tenant, $unit, $amount, $asOf)];
    }

    public function simulate(ImportRow $row, ImportRun $run): RowPlan
    {
        $tenant = $this->tenant((string) $row->text('tenant'), $run);
        $unit = $this->unitOf($row, $run);
        $amount = Money::kobo((int) $row->amountKobo('amount'));
        $asOf = $row->date('as_of') ?? PaymentDates::today();
        $match = $this->match($row, $tenant, $unit, $asOf);

        return new RowPlan(
            action: match (true) {
                $match === null => ImportAction::Create,
                $match->trashed() => ImportAction::Restore,
                default => ImportAction::Update,
            },
            preview: [
                'tenant' => $tenant->name,
                'unit_label' => $unit?->name,
                'amount' => $amount->isNegative() ? __(':amount credit', ['amount' => $amount->abs()->format()]) : __(':amount owed', ['amount' => $amount->format()]),
                'as_of' => $asOf->format('d/m/Y'),
                'Becomes' => match (true) { // not a column: shown under this heading
                    $amount->isNegative() => __('Credit for the next bill'),
                    $asOf->lessThan(PaymentDates::today()) => __('Opening balance bill, overdue'),
                    default => __('Opening balance bill, due today'),
                },
            ],
        );
    }

    public function upsert(ImportRow $row, ImportRun $run): UpsertResult
    {
        $tenant = $this->tenant((string) $row->text('tenant'), $run);
        $unit = $this->unitOf($row, $run);
        $amount = Money::kobo((int) $row->amountKobo('amount'));
        $asOf = $row->date('as_of');
        assert($asOf !== null);

        if ($tenant->tenant === null || ($unit !== null && $unit->unit === null) || ($amount->isPositive() && $unit === null)) {
            return new UpsertResult(ImportAction::Skip, reason: __('The tenant or the unit is no longer on file.'));
        }

        $match = $this->match($row, $tenant, $unit, $asOf);

        if ($match !== null && ! $match->trashed()) {
            return new UpsertResult(ImportAction::Update, $match->id);
        }

        $balance = $match ?? new OpeningBalance;
        $description = $row->text('description');
        $lease = $match === null && $unit?->unit !== null ? $this->leaseOf($tenant->tenant->id, $unit->unit->id) : null;

        if ($match === null) {
            $balance->forceFill([
                'tenant_profile_id' => $tenant->tenant->id,
                'property_id' => $unit?->unit?->propertyId,
                'unit_id' => $unit?->unit?->id,
                'lease_id' => $lease?->id,
                'as_of_date' => $asOf,
                'amount_kobo' => $amount,
                'description' => $description,
                'external_ref' => $row->externalRef(),
            ]);
        } else {
            $balance->restore();
        }

        $balance->import_batch_id = $run->batchId();

        if ($balance->isCredit()) {
            $balance->credit_entry_id = app(TenantCredit::class)->addOpeningCredit($balance->tenant_profile_id, $balance->amount_kobo->abs(), $run->batchId(), $balance->description ?? __('Credit on :date, brought over from earlier records', ['date' => $balance->as_of_date->format('d/m/Y')]))->id;
        } elseif ($match === null) {
            assert($unit?->unit !== null);
            $balance->bill_id = app(OpeningBalanceBills::class)->issue(new OpeningBalanceBill(
                tenantProfileId: $balance->tenant_profile_id,
                propertyId: $unit->unit->propertyId,
                unitId: $unit->unit->id,
                asOf: $asOf,
                amount: $amount,
                importBatchId: $run->batchId(),
                description: $description,
                leaseId: $lease?->id,
                leaseNumber: $lease?->contractNumber,
                cycle: $lease?->cycle,
            ))->id;
        } elseif ($balance->bill_id === null || app(OpeningBalanceBills::class)->restore($balance->bill_id, $run->batchId()) === null) {
            throw new RuntimeException('The opening balance\'s bill could not be brought back.');
        }

        $balance->save();

        activity(TenantCredit::LOG)
            ->event($match === null ? 'opening_balance.imported' : 'opening_balance.import_restored')
            ->performedOn($balance)
            ->withProperties([
                'import_batch_id' => $run->batchId(),
                'tenant_profile_id' => $balance->tenant_profile_id,
                'amount_kobo' => $balance->amount_kobo->kobo,
                'as_of' => $balance->as_of_date->toDateString(),
                'bill_id' => $balance->bill_id,
                'credit_entry_id' => $balance->credit_entry_id,
            ])
            ->log($balance->isCredit() ? 'Opening credit imported' : 'Opening balance imported');

        return new UpsertResult($match === null ? ImportAction::Create : ImportAction::Restore, $balance->id);
    }

    public function rollback(int $batchId): int
    {
        $count = 0;
        $credit = app(TenantCredit::class);
        $bills = app(OpeningBalanceBills::class);

        foreach (OpeningBalance::query()->where('import_batch_id', $batchId)->orderBy('id')->lockForUpdate()->get() as $balance) {
            $done = $balance->isCredit()
                ? $balance->credit_entry_id !== null && $credit->reverseOpeningCredit(TenantCreditEntry::query()->findOrFail($balance->credit_entry_id), $batchId)
                : $balance->bill_id !== null && $bills->withdraw($balance->bill_id);

            if (! $done) {
                // usedSince() refuses the rollback before; this guards a race.
                throw new LogicException('An opening balance of this import has been used since.');
            }

            $balance->delete();

            activity(TenantCredit::LOG)
                ->event('opening_balance.import_rolled_back')
                ->performedOn($balance)
                ->withProperties(['import_batch_id' => $batchId, 'tenant_profile_id' => $balance->tenant_profile_id, 'amount_kobo' => $balance->amount_kobo->kobo])
                ->log('Opening balance removed with its import');
            $count++;
        }

        return $count;
    }

    /**
     * Used once an opening balance bill of the batch was paid (in part, by a
     * payment or by credit), voided, or named by a payment, or once an
     * imported credit was used for a bill.
     */
    public function usedSince(int $batchId): bool
    {
        $ledger = app(BillLedger::class);

        foreach (OpeningBalance::query()->where('import_batch_id', $batchId)->orderBy('id')->get() as $balance) {
            if ($balance->isCredit()) {
                $used = TenantCreditEntry::query()
                    ->where('tenant_profile_id', $balance->tenant_profile_id)
                    ->where('reason', CreditReason::AppliedToBill)
                    ->where('id', '>', (int) $balance->credit_entry_id)
                    ->exists();
            } else {
                $bill = $balance->bill_id !== null ? $ledger->balance($balance->bill_id) : null;
                $used = $bill === null || $bill->isVoid() || $bill->paid->isPositive()
                    || Payment::query()->withTrashed()->where('bill_id', $balance->bill_id)->exists();
            }

            if ($used) {
                return true;
            }
        }

        return false;
    }

    /** @return list<RowIssue> */
    private function matchIssues(?OpeningBalance $match, TenantReference $tenant, ?UnitReference $unit, Money $amount, CarbonImmutable $asOf): array
    {
        if ($match === null) {
            return [];
        }

        if ($tenant->key !== 'id:'.$match->tenant_profile_id || $unit?->key !== ($match->unit_id !== null ? 'id:'.$match->unit_id : null) || ! $match->as_of_date->equalTo($asOf)) {
            return [RowIssue::error(ImportColumn::EXTERNAL_REF, __('This ID is another opening balance on GoProperty (:name, :date). Check the ID.', ['name' => $tenant->name, 'date' => $match->as_of_date->format('d/m/Y')]))];
        }

        if (! $match->amount_kobo->equals($amount)) {
            return [RowIssue::error('amount', $match->trashed()
                ? __('This opening balance was :amount when an import was rolled back. Import it with that amount, or give it a new Your ID and as-of date.', ['amount' => self::describe($match->amount_kobo)])
                : __('This opening balance is on GoProperty already as :amount, and an import does not change it. Change it on the tenant\'s bills or credit instead.', ['amount' => self::describe($match->amount_kobo)]))];
        }

        if ($match->trashed()) {
            return [RowIssue::warning(null, __('This opening balance was removed when an import was rolled back. Importing brings it back.'))];
        }

        return [RowIssue::warning(null, __('This opening balance is on GoProperty already: nothing changes.'))];
    }

    /** The opening balance the row is about: by Your ID, then by tenant + unit + as-of date, rolled-back ones included (live first). */
    private function match(ImportRow $row, TenantReference $tenant, ?UnitReference $unit, CarbonImmutable $asOf): ?OpeningBalance
    {
        $ref = $row->externalRef();

        if ($ref !== null && ($found = OpeningBalance::query()->withTrashed()->where('external_ref', $ref)->first()) !== null) {
            return $found;
        }

        // By the ids behind the keys: a tenant or unit the file brings back after a rollback is known by its id too.
        $tenantId = self::idOf($tenant->key);
        $unitId = $unit !== null ? self::idOf($unit->key) : null;

        if ($tenantId === null || ($unit !== null && $unitId === null)) {
            return null;
        }

        return OpeningBalance::query()->withTrashed()
            ->where('tenant_profile_id', $tenantId)
            ->where('unit_id', $unitId)
            ->whereDate('as_of_date', $asOf->toDateString())
            ->orderByRaw('deleted_at IS NULL DESC')
            ->orderByDesc('id')
            ->first();
    }

    /** The tenant's lease on the unit the bill is for: the active one, else the latest one signed (none: the bill is for the unit). */
    private function leaseOf(int $tenantId, int $unitId): ?LeaseSummary
    {
        $leases = array_values(array_filter(
            app(TenantLeases::class)->forTenant($tenantId),
            static fn (LeaseSummary $lease): bool => $lease->unitId === $unitId && $lease->contractNumber !== null,
        ));

        foreach ($leases as $lease) {
            if ($lease->status === 'active') {
                return $lease;
            }
        }

        return $leases[0] ?? null;
    }

    private function naturalKey(TenantReference $tenant, ?UnitReference $unit, CarbonImmutable $asOf): string
    {
        return $tenant->key.'|'.($unit->key ?? '-').'|'.$asOf->toDateString();
    }

    private function tenant(string $reference, ImportRun $run): TenantReference
    {
        $known = $this->tenantRefs[$run] ?? [];

        if (! isset($known[$reference])) {
            $known[$reference] = app(ImportedTenants::class)->resolve($reference, $run);
            $this->tenantRefs[$run] = $known;
        }

        return $known[$reference];
    }

    private function unitOf(ImportRow $row, ImportRun $run): ?UnitReference
    {
        $building = $row->text('building');
        $label = $row->text('unit_label');

        if ($building === null || $label === null) {
            return null;
        }

        $key = mb_strtolower($building)."\n".mb_strtolower($label);
        $known = $this->unitRefs[$run] ?? [];

        if (! isset($known[$key])) {
            $known[$key] = app(ImportedUnits::class)->resolve($building, $label, $run);
            $this->unitRefs[$run] = $known;
        }

        return $known[$key];
    }

    /** @return list<RowIssue> */
    private function refIssues(ImportRow $row, ImportRun $run): array
    {
        $ref = $row->externalRef();

        if ($ref === null) {
            return [];
        }

        if (mb_strlen($ref) > 100) {
            return [RowIssue::error(ImportColumn::EXTERNAL_REF, __('Use at most :max characters for your ID.', ['max' => 100]))];
        }

        if (($earlier = $run->rowOf(self::ROWS_BY_REF, $ref)) !== null) {
            return [RowIssue::error(ImportColumn::EXTERNAL_REF, __('This ID is already on row :row. Each opening balance goes on one row.', ['row' => $earlier]))];
        }

        $run->remember(self::ROWS_BY_REF, $ref, $row->number);

        return [];
    }

    /** @param  list<RowIssue>  $issues */
    private function amountCell(ImportRow $row, array &$issues): ?Money
    {
        try {
            $kobo = $row->amountKobo('amount');
        } catch (InvalidCellValue $invalid) {
            $issues[] = RowIssue::error('amount', $invalid->getMessage());

            return null;
        }

        if ($kobo === null) {
            $issues[] = RowIssue::error('amount', __('Enter what the tenant owed, or a credit as a negative amount such as -50,000.'));

            return null;
        }

        if ($kobo === 0) {
            $issues[] = RowIssue::error('amount', __('A balance of ₦0 needs no row: leave it out.'));

            return null;
        }

        if (abs($kobo) >= Money::naira(self::MAX_NAIRA)->kobo) {
            $issues[] = RowIssue::error('amount', __('Enter an amount below ₦10,000,000,000.'));

            return null;
        }

        return Money::kobo($kobo);
    }

    /** @param  list<RowIssue>  $issues */
    private function asOfCell(ImportRow $row, array &$issues): ?CarbonImmutable
    {
        try {
            $date = $row->date('as_of');
        } catch (InvalidCellValue $invalid) {
            $issues[] = RowIssue::error('as_of', $invalid->getMessage());

            return null;
        }

        if ($date === null) {
            $issues[] = RowIssue::error('as_of', __('Enter the day the balance stood, as DD/MM/YYYY.'));

            return null;
        }

        if ($date->greaterThan(PaymentDates::today())) {
            $issues[] = RowIssue::error('as_of', __('An opening balance is what was owed on a day that has come: use today or an earlier date.'));

            return null;
        }

        return $date;
    }

    /** @return list<RowIssue> */
    private function descriptionIssues(ImportRow $row): array
    {
        $text = $row->text('description');

        return match (true) {
            $text === null => [],
            preg_match('/[<>]/', $text) === 1 => [RowIssue::error('description', __('Use plain text only (no < or >).'))],
            mb_strlen($text) > self::MAX_DESCRIPTION => [RowIssue::error('description', __('Use at most :max characters.', ['max' => self::MAX_DESCRIPTION]))],
            default => [],
        };
    }

    /** The record id behind a lookup key ("id:42"), null for one the file adds. */
    private static function idOf(?string $key): ?int
    {
        return $key !== null && str_starts_with($key, 'id:') ? (int) substr($key, 3) : null;
    }

    private static function describe(Money $amount): string
    {
        return $amount->isNegative() ? __(':amount credit', ['amount' => $amount->abs()->format()]) : __(':amount owed', ['amount' => $amount->format()]);
    }

    /** @param  list<RowIssue>  $issues */
    private static function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue->isError()) {
                return true;
            }
        }

        return false;
    }
}
