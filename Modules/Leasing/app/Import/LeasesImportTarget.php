<?php

declare(strict_types=1);

namespace Modules\Leasing\Import;

use App\Branding\Branding;
use App\Documents\DocumentType;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Import\Contracts\ImportAction;
use Modules\Import\Contracts\ImportColumn;
use Modules\Import\Contracts\ImportRow;
use Modules\Import\Contracts\ImportRun;
use Modules\Import\Contracts\ImportTarget;
use Modules\Import\Contracts\ImportUsage;
use Modules\Import\Contracts\ImportUsages;
use Modules\Import\Contracts\InvalidCellValue;
use Modules\Import\Contracts\RowIssue;
use Modules\Import\Contracts\RowPlan;
use Modules\Import\Contracts\UpsertResult;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Enums\DepositEntryType;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Leases\LeaseDates;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Leases\LeaseTerms;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Models\LeaseContract;
use Modules\Leasing\Models\LeaseDepositEntry;
use Modules\Properties\Contracts\ImportedUnits;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Properties\Contracts\UnitReference;
use Modules\Tenants\Contracts\ImportedTenants;
use Modules\Tenants\Contracts\TenantReference;
use WeakMap;

/**
 * The Leases sheet (DATA_IMPORT.md § 1 row 5, § 3; P03-13a): ongoing rents
 * brought over from another system, and past leases as history. One lease
 * per row: the tenant (phone or Your ID, from the Tenants sheet or on
 * file), the unit (building + unit name, from the Units sheet or on file),
 * the term, the billing cycle, the rent per period, the deposit held,
 * **paid until**, a contract number to keep (or GoProperty numbers it), and
 * a status for leases that already ended (expired history, or
 * month-to-month).
 *
 * Every lease is written by LeaseService::importOngoing() (AGENT.md § 3
 * rule 5): active at once without back-billing (`next_billing_date` is the
 * period after "paid until", never a past one: OngoingLeaseDates), the
 * deposit as a ledger entry, the unit let through LeaseImported. No
 * contract is issued, no tenant is contacted and no reminder is set by the
 * import (AGENT.md § 4): "Invite tenants now" on the report is the
 * agency's choice. A row matches a lease by Your ID, then by tenant + unit
 * + start date, archived (rolled-back) leases included: re-importing
 * updates instead of adding it twice, and a lease that has bills since
 * cannot be changed by an import.
 *
 * Read through other modules' contracts only (ARCHITECTURE.md § 8 rule 3):
 * units through Properties' ImportedUnits and UnitDirectory, tenants
 * through Tenants' ImportedTenants, bills through Import's ImportUsages.
 */
final class LeasesImportTarget implements ImportTarget
{
    public const string KEY = 'leases';

    private const string ROWS_BY_REF = 'leases:ref';

    private const string ROWS_BY_NUMBER = 'leases:number';

    /** Ongoing leases of the file, by unit key (one active lease per unit). */
    private const string ONGOING_BY_UNIT = 'leases:unit';

    /** Ongoing leases of the file, by tenant key. */
    private const string ONGOING_BY_TENANT = 'leases:tenant';

    /** @var WeakMap<ImportRun, array<string, TenantReference>> */
    private WeakMap $tenantRefs;

    /** @var WeakMap<ImportRun, array<string, UnitReference>> */
    private WeakMap $unitRefs;

    /**
     * Only stateless collaborators are kept: this target is a singleton, so
     * whatever reads the organization (its context is scoped: a queue
     * worker resets it between jobs) is resolved when used.
     */
    public function __construct(
        private readonly ImportUsages $usages,
        private readonly OngoingLeaseDates $dates,
    ) {
        $this->tenantRefs = new WeakMap;
        $this->unitRefs = new WeakMap;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Leases';
    }

    /** After owners, buildings, units and tenants (DATA_IMPORT.md § 2.4), before balances and history. */
    public function order(): int
    {
        return 50;
    }

    public function instructions(): string
    {
        return 'One row per lease, ongoing or past. "Paid until" is the last day the tenant has paid for: GoProperty bills from the period after it and never bills the past (put arrears on the Opening balances sheet). Leases that already ended come in as expired history, unless you choose Month-to-month. Nobody is contacted: you invite tenants after the import.';
    }

    public function columns(): array
    {
        return [
            ImportColumn::externalRef('L-001'),
            new ImportColumn('tenant', 'Tenant', required: true, aliases: ['tenant phone', 'tenant phone number', 'tenant id', 'tenant ref', 'phone', 'phone number', 'tenant mobile'], example: '0803 123 4567', help: 'The tenant\'s phone number, or Your ID from the Tenants sheet.'),
            new ImportColumn('building', 'Building', required: true, aliases: ['building name', 'property', 'property name', 'estate'], example: 'Palm Court', help: 'The building\'s name or Your ID, from the Buildings sheet or already on GoProperty.'),
            new ImportColumn('unit_label', 'Unit', required: true, aliases: ['unit name', 'unit label', 'unit number', 'flat', 'flat number', 'apartment', 'shop number'], example: 'Flat 1', help: 'The unit\'s name in its building, as on the Units sheet.'),
            new ImportColumn('start_date', 'Start date', required: true, aliases: ['start', 'lease start', 'commencement date', 'from', 'move in date', 'move-in date'], example: '01/01/2025', help: 'DD/MM/YYYY.'),
            new ImportColumn('end_date', 'End date', required: true, aliases: ['end', 'lease end', 'expiry date', 'expiry', 'to', 'expires'], example: '31/12/2027', help: 'DD/MM/YYYY: the last day of the lease.'),
            new ImportColumn('billing_cycle', 'Billing cycle', required: true, aliases: ['cycle', 'payment cycle', 'rent cycle', 'frequency', 'payment frequency', 'billing frequency'], example: BillingCycle::Annual->label(), options: array_map(static fn (BillingCycle $cycle): string => $cycle->label(), BillingCycle::cases())),
            new ImportColumn('rent', 'Rent per period', required: true, aliases: ['rent', 'rent amount', 'rent per cycle', 'amount', 'rent (per period)'], example: '₦1,500,000', help: 'The rent for one period of the billing cycle: a year\'s rent for a yearly lease, a month\'s for a monthly one.'),
            new ImportColumn('deposit', 'Deposit held', aliases: ['deposit', 'caution fee', 'caution deposit', 'security deposit', 'caution'], example: '₦150,000', help: 'The deposit (caution fee) you hold for refund at move-out. Recorded on the lease; it is not billed. Empty: none.'),
            new ImportColumn('paid_until', 'Paid until', aliases: ['paid to', 'paid up to', 'rent paid until', 'paid through', 'paid till'], example: '31/12/2026', help: 'The last day the tenant has already paid for. GoProperty bills from the period after it and never bills past periods. Empty: nothing paid since the start.'),
            new ImportColumn('due_day_offset', 'Due days after period start', aliases: ['due day offset', 'days to pay', 'grace days', 'due after'], example: '0', help: 'How many days after a period starts its rent is due. Empty: on the first day.'),
            new ImportColumn('auto_renew', 'Auto-renew', aliases: ['auto renew', 'autorenew', 'renews automatically'], example: 'No', options: ['Yes', 'No']),
            new ImportColumn('contract_number', 'Contract number', aliases: ['lease number', 'lease no', 'agreement number', 'contract no', 'tenancy number'], help: 'Your own contract number, kept as it is. Empty: GoProperty numbers the lease.'),
            new ImportColumn('status', 'Status', aliases: ['lease status', 'state'], help: 'Empty: active, or expired when the end date has passed. Month-to-month: the lease ended but the tenant stays, paying monthly.', options: array_map(static fn (ImportedLeaseStatus $status): string => $status->label(), ImportedLeaseStatus::cases())),
            new ImportColumn('notes', 'Notes', aliases: ['remarks', 'comments', 'note'], example: ''),
        ];
    }

    public function validate(ImportRow $row, ImportRun $run): array
    {
        $issues = $this->refIssues($row, $run);
        $today = LeaseDates::today();

        $tenant = ($text = $row->text('tenant')) !== null ? $this->tenant($text, $run) : null;
        $unit = $this->unitOf($row, $run);

        if ($tenant === null) {
            $issues[] = RowIssue::error('tenant', __('Enter the tenant\'s phone number or Your ID from the Tenants sheet.'));
        } elseif ($tenant->error !== null) {
            $issues[] = RowIssue::error('tenant', $tenant->error);
        }

        if ($row->text('building') === null) {
            $issues[] = RowIssue::error('building', __('Enter the unit\'s building: its name or Your ID.'));
        }

        if ($row->text('unit_label') === null) {
            $issues[] = RowIssue::error('unit_label', __('Enter the unit\'s name, such as Flat 3B.'));
        } elseif ($unit?->error !== null) {
            $issues[] = RowIssue::error($unit->aboutBuilding ? 'building' : 'unit_label', $unit->error);
        }

        [$start, $end, $paidUntil] = [$this->dateCell($row, 'start_date', $issues, required: true), $this->dateCell($row, 'end_date', $issues, required: true), $this->dateCell($row, 'paid_until', $issues)];
        $cycle = $this->cycleCell($row, $issues);
        $rent = $this->amountCell($row, 'rent', $issues, required: true);
        $deposit = $this->amountCell($row, 'deposit', $issues);
        $status = $this->statusCell($row, $issues);
        $this->dueOffsetCell($row, $cycle, $issues);
        $this->autoRenewCell($row, $issues);
        $issues = [...$issues, ...self::plainText($row, 'contract_number', 40), ...self::plainText($row, 'notes', LeaseTerms::MAX_NOTES, '')];

        if ($start !== null && $end !== null && ! $end->greaterThan($start)) {
            $issues[] = RowIssue::error('end_date', __('The lease must end after it starts (:date).', ['date' => $start->format('d/m/Y')]));
        }

        if ($rent !== null && $rent <= 0) {
            $issues[] = RowIssue::error('rent', __('Enter a rent above ₦0.'));
        }

        if (self::hasErrors($issues) || $start === null || $end === null || $cycle === null || $rent === null || $tenant === null || $unit === null) {
            return $issues;
        }

        $status = $this->resolvedStatus($status, $end, $today);
        $issues = [...$issues, ...$this->statusIssues($row, $status, $cycle, $end, $today)];

        if ($paidUntil !== null && $paidUntil->lessThan($start)) {
            $issues[] = RowIssue::error('paid_until', __('Paid until must be on or after the start date (:date). Leave it empty when nothing has been paid.', ['date' => $start->format('d/m/Y')]));
        } elseif ($paidUntil !== null && $status !== ImportedLeaseStatus::MonthToMonth && $paidUntil->greaterThan($end)) {
            $issues[] = RowIssue::error('paid_until', __('Paid until must be on or before the end date (:date).', ['date' => $end->format('d/m/Y')]));
        }

        if (self::hasErrors($issues)) {
            return $issues;
        }

        $match = $this->match($row, $tenant, $unit, $start);
        $issues = [...$issues, ...$this->matchIssues($row, $match, $tenant, $unit, $status, $cycle, $start, $end, $rent, $deposit ?? 0, $paidUntil)];

        if (($match === null || $match->trashed()) && $this->organization()->status === OrganizationStatus::Restricted) {
            $issues[] = RowIssue::error(null, LeaseService::restrictedMessage());
        }
        $issues = [...$issues, ...$this->numberIssues($row, $run, $match)];

        if (self::hasErrors($issues)) {
            return $issues;
        }

        if ($status->isOngoing()) {
            $issues = [...$issues, ...$this->ongoingIssues($row, $run, $match, $tenant, $unit, $cycle, $start, $end, $rent, $paidUntil, $status, $today)];
        } else {
            $issues[] = RowIssue::warning('end_date', __('This lease ended on :date, so it is imported as expired (history): it is not billed and does not let the unit.', ['date' => $end->format('d/m/Y')]));
        }

        if (! self::hasErrors($issues) && ($number = $row->text('contract_number')) !== null) {
            $run->remember(self::ROWS_BY_NUMBER, $number, $row->number);
        }

        return $issues;
    }

    public function simulate(ImportRow $row, ImportRun $run): RowPlan
    {
        $today = LeaseDates::today();
        $tenant = $this->tenant((string) $row->text('tenant'), $run);
        $unit = $this->unitOf($row, $run);
        $start = $row->date('start_date') ?? $today;
        $end = $row->date('end_date') ?? $today;
        $paidUntil = $row->date('paid_until');
        $cycle = self::cycle((string) $row->text('billing_cycle')) ?? BillingCycle::Annual;
        $status = $this->resolvedStatus(($text = $row->text('status')) !== null ? ImportedLeaseStatus::fromInput($text) : null, $end, $today);
        $rent = Money::kobo((int) $row->amountKobo('rent'));
        $match = $unit !== null ? $this->match($row, $tenant, $unit, $start) : null;
        $firstBill = $status->isOngoing() ? $this->dates->firstBillingDate($cycle, $start, $paidUntil, $today) : null;
        $action = match (true) {
            $match === null => ImportAction::Create,
            $match->trashed() => ImportAction::Restore,
            default => ImportAction::Update,
        };

        return new RowPlan(
            action: $action,
            preview: [
                'tenant' => $tenant->name,
                'unit_label' => $unit?->name,
                'status' => $status->label(),
                'billing_cycle' => $cycle->label(),
                'rent' => $rent->format(),
                'paid_until' => $paidUntil?->format('d/m/Y'),
                'Billed from' => $firstBill?->format('d/m/Y'), // not a column: shown under this heading
                'end_date' => $this->dates->endDate($status, $start, $end, $paidUntil, $today)->format('d/m/Y'),
            ],
            yearlyCollectionsKobo: $status->isOngoing() ? self::yearly($rent, $cycle)->kobo : 0,
        );
    }

    public function upsert(ImportRow $row, ImportRun $run): UpsertResult
    {
        $today = LeaseDates::today();
        $tenant = $this->tenant((string) $row->text('tenant'), $run)->tenant;
        $unit = $this->unitOf($row, $run)?->unit;

        if ($tenant === null || $unit === null) {
            return new UpsertResult(ImportAction::Skip, reason: __('The tenant or the unit is no longer on file.'));
        }

        $start = $row->date('start_date');
        $end = $row->date('end_date');
        $cycle = self::cycle((string) $row->text('billing_cycle'));
        assert($start !== null && $end !== null && $cycle !== null);
        $status = $this->resolvedStatus(($text = $row->text('status')) !== null ? ImportedLeaseStatus::fromInput($text) : null, $end, $today);
        $match = Lease::query()->withTrashed()->find($this->matchId($row, $tenant->id, $unit->id, $start));

        $data = new ImportedLease(
            tenantProfileId: $tenant->id,
            unitId: $unit->id,
            status: $status,
            cycle: $cycle,
            start: $start,
            end: $end,
            rent: Money::kobo((int) $row->amountKobo('rent')),
            deposit: Money::kobo($row->amountKobo('deposit') ?? 0),
            dueDayOffset: $row->integer('due_day_offset') ?? 0,
            paidUntil: $row->date('paid_until'),
            autoRenew: self::yes($row->text('auto_renew')),
            contractNumber: $row->text('contract_number'),
            externalRef: $row->externalRef(),
            notes: $row->text('notes'),
        );

        try {
            $result = app(LeaseService::class)->importOngoing($run->batchId(), $data, $match instanceof Lease ? $match : null);
        } catch (ValidationException $refused) {
            // Checked again under the locks (LeaseRefused): the row is skipped with the reason.
            return new UpsertResult(ImportAction::Skip, reason: (string) collect($refused->errors())->flatten()->first());
        }

        return new UpsertResult($result->action, $result->lease->id);
    }

    public function rollback(int $batchId): int
    {
        return app(LeaseService::class)->rollBackImport($batchId);
    }

    /**
     * A lease of the batch is used once something happened to it that a
     * rollback cannot undo: a bill (or a payment against one: Billing
     * answers through ImportUsages), a renewal, an issued contract, a
     * deposit refunded or kept, or an end recorded on its page.
     */
    public function usedSince(int $batchId): bool
    {
        $leases = Lease::query()->where('import_batch_id', $batchId);

        return (clone $leases)->whereIn('status', [LeaseStatus::Terminated, LeaseStatus::Renewed])->exists()
            || Lease::query()->whereIn('renewal_of_lease_id', (clone $leases)->select('id'))->exists()
            || LeaseContract::query()->whereIn('lease_id', (clone $leases)->select('id'))->exists()
            || LeaseDepositEntry::query()->whereIn('lease_id', (clone $leases)->select('id'))->whereIn('type', [DepositEntryType::Refunded, DepositEntryType::Kept])->exists()
            || $this->usages->used(ImportUsage::LEASE, (clone $leases)->select('id'), $batchId);
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
            return [RowIssue::error(ImportColumn::EXTERNAL_REF, __('This ID is already on row :row. Each lease goes on one row.', ['row' => $earlier]))];
        }

        $run->remember(self::ROWS_BY_REF, $ref, $row->number);

        return [];
    }

    /** @return list<RowIssue> */
    private function statusIssues(ImportRow $row, ImportedLeaseStatus $status, BillingCycle $cycle, CarbonImmutable $end, CarbonImmutable $today): array
    {
        $ended = $end->lessThan($today);

        return match (true) {
            $status === ImportedLeaseStatus::Active && $ended => [RowIssue::error('status', __('This lease ended on :date. Leave Status empty or choose Expired to import it as history, or Month-to-month if the tenant stays and pays monthly.', ['date' => $end->format('d/m/Y')]))],
            $status === ImportedLeaseStatus::Expired && ! $ended => [RowIssue::error('status', __('This lease runs until :date, so it has not expired. Leave Status empty to import it as active.', ['date' => $end->format('d/m/Y')]))],
            $status === ImportedLeaseStatus::MonthToMonth && ! $ended => [RowIssue::error('status', __('This lease runs until :date. Month-to-month is for a lease that has ended while the tenant stays.', ['date' => $end->format('d/m/Y')]))],
            $status === ImportedLeaseStatus::MonthToMonth && $cycle !== BillingCycle::Monthly => [RowIssue::error('billing_cycle', __('A month-to-month lease is billed monthly: set Billing cycle to Monthly and the rent to one month\'s rent.'))],
            default => [],
        };
    }

    /**
     * Checks against the lease the row matched (re-import): same tenant and
     * unit, same state, no new terms once bills exist, the deposit kept.
     *
     * @return list<RowIssue>
     */
    private function matchIssues(ImportRow $row, ?Lease $match, TenantReference $tenant, UnitReference $unit, ImportedLeaseStatus $status, BillingCycle $cycle, CarbonImmutable $start, CarbonImmutable $end, int $rent, int $deposit, ?CarbonImmutable $paidUntil): array
    {
        if ($match === null) {
            return [];
        }

        // A tenant or unit the file brings back (after a rollback) is known by its id too.
        if ($tenant->key !== 'id:'.$match->tenant_profile_id || $unit->key !== 'id:'.$match->unit_id) {
            return [RowIssue::error(ImportColumn::EXTERNAL_REF, __('This ID is the lease :contract, of another tenant or unit. A lease cannot move to another tenant or unit: check the ID.', ['contract' => $match->reference()]))];
        }

        if ($match->trashed()) {
            return [RowIssue::warning(null, __('This lease was removed when an import was rolled back. Importing brings it back.'))];
        }

        $wanted = $status->isOngoing() ? LeaseStatus::Active : LeaseStatus::Expired;

        if ($match->status !== $wanted) {
            return [RowIssue::error('status', __('This lease (:contract) is :status on GoProperty. An import cannot change that: do it on the lease\'s page.', ['contract' => $match->reference(), 'status' => mb_strtolower(__($match->status->label()))]))];
        }

        $changed = $match->billing_cycle !== $cycle
            || ! $match->start_date->equalTo($start)
            || ($status !== ImportedLeaseStatus::MonthToMonth && ! $match->end_date->equalTo($end))
            || $match->rent_amount_kobo->kobo !== $rent
            || $match->due_day_offset !== ($row->integer('due_day_offset') ?? 0)
            || $match->paid_until?->toDateString() !== $paidUntil?->toDateString();

        if ($changed && $this->usages->used(ImportUsage::LEASE, Lease::query()->whereKey($match->id)->select('id'), 0)) {
            return [RowIssue::error(null, __('Bills have been issued for this lease (:contract), so an import cannot change its terms. Change it on its page.', ['contract' => $match->reference()]))];
        }

        $held = Money::kobo((int) LeaseDepositEntry::query()->where('lease_id', $match->id)->sum('amount_kobo'));

        if (LeaseDepositEntry::query()->where('lease_id', $match->id)->exists() && $row->text('deposit') !== null && $held->kobo !== $deposit) {
            return [RowIssue::warning('deposit', __('The deposit held on file stays :held: an import does not change a deposit already recorded.', ['held' => $held->format()]))];
        }

        return [];
    }

    /** @return list<RowIssue> */
    private function numberIssues(ImportRow $row, ImportRun $run, ?Lease $match): array
    {
        $number = $row->text('contract_number');

        if ($number === null) {
            return [];
        }

        if ($match !== null && $match->contract_number !== null) {
            return $match->contract_number === $number ? [] : [RowIssue::error('contract_number', __('This lease\'s contract number is :number on GoProperty. An import does not change it.', ['number' => $match->contract_number]))];
        }

        if (($earlier = $run->rowOf(self::ROWS_BY_NUMBER, $number)) !== null) {
            return [RowIssue::error('contract_number', __('Contract number :number is already on row :row. Each lease needs its own number.', ['number' => $number, 'row' => $earlier]))];
        }

        if (Lease::query()->withTrashed()->where('contract_number', $number)->when($match !== null, fn ($query) => $query->whereKeyNot($match?->id))->exists()) {
            return [RowIssue::error('contract_number', __('Contract number :number is already another lease\'s.', ['number' => $number]))];
        }

        $prefix = DocumentType::LeaseContract->prefix(Branding::fromOrganization($this->organization()));

        if (preg_match('/^'.preg_quote($prefix, '/').'-\d{4}-\d+$/i', $number) === 1) {
            return [RowIssue::error('contract_number', __(':number looks like one of GoProperty\'s own numbers (:prefix-…), which it gives to new leases. Leave it empty and GoProperty numbers the lease, or use your own numbering.', ['number' => $number, 'prefix' => $prefix]))];
        }

        return [];
    }

    /**
     * An ongoing lease lets its unit: one per unit, a unit that can be let,
     * and the warnings of DATA_IMPORT.md § 2.3.
     *
     * @return list<RowIssue>
     */
    private function ongoingIssues(ImportRow $row, ImportRun $run, ?Lease $match, TenantReference $tenant, UnitReference $unit, BillingCycle $cycle, CarbonImmutable $start, CarbonImmutable $end, int $rent, ?CarbonImmutable $paidUntil, ImportedLeaseStatus $status, CarbonImmutable $today): array
    {
        $issues = [];
        $summary = $unit->unit;

        if ($summary !== null) {
            $let = Lease::query()->where('unit_id', $summary->id)->where('status', LeaseStatus::Active)
                ->when($match !== null, fn ($query) => $query->whereKeyNot($match?->id))->first();

            if ($let !== null) {
                $issues[] = RowIssue::error('unit_label', __(':unit is already let (:contract). End that lease first, or import this one as expired.', ['unit' => $unit->name, 'contract' => $let->reference()]));
            } elseif (($match === null || $match->trashed()) && in_array($summary->status, [UnitDirectory::UNDER_MAINTENANCE, UnitDirectory::UNAVAILABLE], true)) {
                $issues[] = RowIssue::error('unit_label', __(':unit is :status. Make it vacant (on its page or the Units sheet) before importing its lease.', ['unit' => $unit->name, 'status' => mb_strtolower(__($summary->statusLabel))]));
            }
        }

        if (($earlier = $run->rowOf(self::ONGOING_BY_UNIT, (string) $unit->key)) !== null) {
            $issues[] = RowIssue::error('unit_label', __(':unit already has an ongoing lease on row :row. A unit has one active lease: import the older one as expired.', ['unit' => $unit->name, 'row' => $earlier]));
        }

        if (self::hasErrors($issues)) {
            return $issues;
        }

        $run->remember(self::ONGOING_BY_UNIT, (string) $unit->key, $row->number);

        if ($tenant->tenant !== null && ($other = Lease::query()->where('tenant_profile_id', $tenant->tenant->id)->where('status', LeaseStatus::Active)->when($match !== null, fn ($query) => $query->whereKeyNot($match?->id))->first()) !== null) {
            $issues[] = RowIssue::warning('tenant', __(':name already has an active lease (:contract). This one is added next to it.', ['name' => $tenant->name, 'contract' => $other->reference()]));
        } elseif (($earlier = $run->rowOf(self::ONGOING_BY_TENANT, (string) $tenant->key)) !== null) {
            $issues[] = RowIssue::warning('tenant', __(':name already has an ongoing lease on row :row. This one is added next to it.', ['name' => $tenant->name, 'row' => $earlier]));
        }

        $run->remember(self::ONGOING_BY_TENANT, (string) $tenant->key, $row->number);

        if ($summary !== null && $summary->baseRent->isPositive() && ($leaseYearly = self::yearlyIfExact(Money::kobo($rent), $cycle)) !== null && ! $leaseYearly->equals($summary->baseRent)) {
            $issues[] = RowIssue::warning('rent', __('The unit\'s asking rent is :unit a year; this lease\'s rent comes to :lease a year. The lease keeps its own rent.', ['unit' => $summary->baseRent->format(), 'lease' => $leaseYearly->format()]));
        }

        if ($status === ImportedLeaseStatus::MonthToMonth) {
            $issues[] = RowIssue::warning('status', __('This lease ended on :ended. It is imported as an active monthly lease, renewing on its own, until :until.', [
                'ended' => $end->format('d/m/Y'),
                'until' => $this->dates->endDate($status, $start, $end, $paidUntil, $today)->format('d/m/Y'),
            ]));
        }

        if (($gap = $this->dates->unbilled($cycle, $start, $paidUntil, $today)) !== null) {
            $issues[] = RowIssue::warning('paid_until', __(':from to :to is not paid, by Paid until, and GoProperty never bills the past: put what the tenant owes for it on the Opening balances sheet. Billing starts on :first.', [
                'from' => $gap[0]->format('d/m/Y'),
                'to' => $gap[1]->format('d/m/Y'),
                'first' => $this->dates->firstBillingDate($cycle, $start, $paidUntil, $today)->format('d/m/Y'),
            ]));
        }

        return $issues;
    }

    /** The lease the row is about: by Your ID, then by tenant + unit + start date, archived (rolled-back) ones included. */
    private function match(ImportRow $row, TenantReference $tenant, UnitReference $unit, CarbonImmutable $start): ?Lease
    {
        $id = $this->matchId($row, $tenant->tenant?->id, $unit->unit?->id, $start);

        return $id !== null ? Lease::query()->withTrashed()->find($id) : null;
    }

    private function matchId(ImportRow $row, ?int $tenantId, ?int $unitId, CarbonImmutable $start): ?int
    {
        $ref = $row->externalRef();

        if ($ref !== null && ($id = Lease::query()->withTrashed()->where('external_ref', $ref)->value('id')) !== null) {
            return (int) $id;
        }

        if ($tenantId === null || $unitId === null) {
            return null;
        }

        $id = Lease::query()->withTrashed()->where('tenant_profile_id', $tenantId)->where('unit_id', $unitId)
            ->whereDate('start_date', $start->toDateString())->whereIn('status', [LeaseStatus::Active, LeaseStatus::Expired])
            ->orderByDesc('id')->value('id');

        return $id !== null ? (int) $id : null;
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

    /** @param  list<RowIssue>  $issues */
    private function dateCell(ImportRow $row, string $column, array &$issues, bool $required = false): ?CarbonImmutable
    {
        try {
            $date = $row->date($column);
        } catch (InvalidCellValue $invalid) {
            $issues[] = RowIssue::error($column, $invalid->getMessage());

            return null;
        }

        if ($date === null && $required) {
            $issues[] = RowIssue::error($column, $column === 'start_date' ? __('Enter the day the lease started, as DD/MM/YYYY.') : __('Enter the last day of the lease, as DD/MM/YYYY.'));
        }

        return $date;
    }

    /** @param  list<RowIssue>  $issues */
    private function cycleCell(ImportRow $row, array &$issues): ?BillingCycle
    {
        $text = $row->text('billing_cycle');
        $cycle = $text !== null ? self::cycle($text) : null;

        if ($text === null) {
            $issues[] = RowIssue::error('billing_cycle', __('Choose the billing cycle, such as Yearly or Monthly.'));
        } elseif ($cycle === null) {
            $issues[] = RowIssue::error('billing_cycle', __('Choose one of: :options.', ['options' => implode(', ', array_map(static fn (BillingCycle $c): string => $c->label(), BillingCycle::cases()))]));
        }

        return $cycle;
    }

    /** @param  list<RowIssue>  $issues */
    private function amountCell(ImportRow $row, string $column, array &$issues, bool $required = false): ?int
    {
        try {
            $kobo = $row->amountKobo($column);
        } catch (InvalidCellValue $invalid) {
            $issues[] = RowIssue::error($column, $invalid->getMessage());

            return null;
        }

        if ($kobo === null && $required) {
            $issues[] = RowIssue::error($column, __('Enter the rent for one period of the billing cycle.'));
        } elseif ($kobo !== null && $kobo < 0) {
            $issues[] = RowIssue::error($column, $column === 'rent' ? __('The rent cannot be negative.') : __('The deposit cannot be negative.'));
        } elseif ($kobo !== null && $kobo > Money::naira(LeaseTerms::MAX_NAIRA)->kobo) {
            $issues[] = RowIssue::error($column, __('Enter an amount below ₦10,000,000,000.'));
        }

        return $kobo;
    }

    /** @param  list<RowIssue>  $issues */
    private function statusCell(ImportRow $row, array &$issues): ?ImportedLeaseStatus
    {
        $text = $row->text('status');
        $status = $text !== null ? ImportedLeaseStatus::fromInput($text) : null;

        if ($text !== null && $status === null) {
            $issues[] = RowIssue::error('status', __('Choose one of: :options, or leave it empty.', ['options' => implode(', ', array_map(static fn (ImportedLeaseStatus $s): string => $s->label(), ImportedLeaseStatus::cases()))]));
        }

        return $status;
    }

    /** @param  list<RowIssue>  $issues */
    private function dueOffsetCell(ImportRow $row, ?BillingCycle $cycle, array &$issues): void
    {
        try {
            $offset = $row->integer('due_day_offset');
        } catch (InvalidCellValue $invalid) {
            $issues[] = RowIssue::error('due_day_offset', $invalid->getMessage());

            return;
        }

        $max = LeaseTerms::maxDueDayOffset($cycle);

        if ($offset !== null && ($offset < 0 || $offset > $max)) {
            $issues[] = RowIssue::error('due_day_offset', $cycle === BillingCycle::Daily
                ? __('A daily lease is due on its day: enter 0.')
                : __('Enter a whole number from 0 to :max: the rent must fall due within its period.', ['max' => $max]));
        }
    }

    /** @param  list<RowIssue>  $issues */
    private function autoRenewCell(ImportRow $row, array &$issues): void
    {
        $text = $row->text('auto_renew');

        if ($text !== null && self::yesNo($text) === null) {
            $issues[] = RowIssue::error('auto_renew', __('Enter Yes or No.'));
        }
    }

    /** The status by the file, or by the dates when it says nothing. */
    private function resolvedStatus(?ImportedLeaseStatus $given, CarbonImmutable $end, CarbonImmutable $today): ImportedLeaseStatus
    {
        return $given ?? ($end->lessThan($today) ? ImportedLeaseStatus::Expired : ImportedLeaseStatus::Active);
    }

    private function organization(): Organization
    {
        return app(OrganizationContext::class)->get();
    }

    /** @return list<RowIssue> */
    private static function plainText(ImportRow $row, string $column, int $max, string $forbidden = '<>'): array
    {
        $text = $row->text($column);

        if ($text === null) {
            return [];
        }

        if ($forbidden !== '' && preg_match('/['.preg_quote($forbidden, '/').']/u', $text) === 1) {
            return [RowIssue::error($column, __('Use plain text only (no < or >).'))];
        }

        return mb_strlen($text) > $max ? [RowIssue::error($column, __('Use at most :max characters.', ['max' => $max]))] : [];
    }

    /** A billing cycle as listed, or as agencies write it ("Annual", "Per annum", "Bi-annual", "Every 3 months"…). */
    public static function cycle(string $text): ?BillingCycle
    {
        return BillingCycle::fromText($text);
    }

    private static function yesNo(string $text): ?bool
    {
        return match (mb_strtolower(trim($text))) {
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => null,
        };
    }

    private static function yes(?string $text): bool
    {
        return $text !== null && self::yesNo($text) === true;
    }

    /** One period's rent as a year's, for cycles whose periods fit a year exactly (else null). */
    private static function yearlyIfExact(Money $rent, BillingCycle $cycle): ?Money
    {
        $months = $cycle->months();

        return $months !== null && $months <= 12 && 12 % $months === 0 ? $rent->times(intdiv(12, $months)) : null;
    }

    /** Expected yearly collections for the Transaction Tier forecast (DATA_IMPORT.md § 2.3). */
    private static function yearly(Money $rent, BillingCycle $cycle): Money
    {
        return self::yearlyIfExact($rent, $cycle) ?? match ($cycle) {
            BillingCycle::Daily => $rent->times(365),
            BillingCycle::Weekly => $rent->times(52),
            default => $rent->dividedBy(2), // biennial
        };
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
