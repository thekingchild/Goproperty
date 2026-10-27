<?php

declare(strict_types=1);

namespace Modules\Leasing\Lists;

use App\Exports\ExportCell;
use App\Exports\ExportColumn;
use App\Exports\ListExport;
use App\Exports\ListFilters;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Contracts\CycleCalculator;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Leases\LeaseDates;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * The leases list (/o/{slug}/leases, Livewire\LeaseIndex) and its export
 * (P02-07, AGENT.md § 5): the organization's leases in the member's
 * buildings (an assigned agent: theirs only, through
 * Modules\Properties\Contracts\BuildingAccess), filtered by status,
 * building, billing cycle and "ending soon" (active, ending within the
 * organization's notice window), with a search over the contract number
 * and the tenant's name, phone or email (Modules\Tenants\Contracts\
 * TenantDirectory); newest start first. Rows carry the tenant, unit and
 * building names (LeaseParties). No contact details are exported (names
 * only), so staff may export it while impersonating, audited.
 *
 * Built like App\Exports\QueryListExport (the page paginates query(), the
 * export reads it CHUNK rows at a time), but its rows are LeaseRow
 * values carrying those names, looked up once per chunk.
 */
final class LeaseList implements ListExport
{
    public const int CHUNK = 500;

    public function __construct(
        private readonly BuildingAccess $access,
        private readonly TenantDirectory $tenants,
        private readonly LeaseParties $parties,
        private readonly CycleCalculator $calculator,
        private readonly LeaseService $leases,
    ) {}

    public function key(): string
    {
        return 'leases';
    }

    public function title(): string
    {
        return 'Leases';
    }

    public function filters(array $input): array
    {
        return ListFilters::set([
            'search' => ListFilters::search($input['search'] ?? null),
            'status' => ListFilters::option($input['status'] ?? null, LeaseStatus::values()),
            'building' => ListFilters::id($input['building'] ?? null),
            'cycle' => ListFilters::option($input['cycle'] ?? null, BillingCycle::values()),
            'ending' => ListFilters::flag($input['ending'] ?? null),
        ]);
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', Lease::class);
    }

    /**
     * The list's rows: organization scope, narrowing, filters, search,
     * newest start first.
     *
     * @param  array<string, string|int|bool>  $filters  cleaned
     * @return Builder<Lease>
     */
    public function query(User $user, array $filters): Builder
    {
        $search = is_string($filters['search'] ?? null) ? $filters['search'] : null;
        $status = LeaseStatus::tryFrom((string) ($filters['status'] ?? ''));
        $building = is_int($filters['building'] ?? null) ? $filters['building'] : null;
        $cycle = BillingCycle::tryFrom((string) ($filters['cycle'] ?? ''));
        $limited = $this->access->limitedTo($user);

        return Lease::query()
            ->when($limited !== null, fn (Builder $query) => $query->whereIn('property_id', $limited ?? []))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($building !== null, fn (Builder $query) => $query->where('property_id', $building))
            ->when($cycle !== null, fn (Builder $query) => $query->where('billing_cycle', $cycle))
            ->when(($filters['ending'] ?? false) === true, fn (Builder $query) => $query->where('status', LeaseStatus::Active)
                ->where('end_date', '<=', $this->calculator->daysAfter(LeaseDates::today(), $this->leases->endingSoonDays())->toDateString()))
            ->when($search !== null, function (Builder $query) use ($search): void {
                $tenantIds = $this->tenants->search((string) $search);

                $query->where(fn (Builder $query) => $query
                    ->where('contract_number', 'like', ListFilters::like((string) $search))
                    ->when($tenantIds !== [], fn (Builder $query) => $query->orWhereIn('tenant_profile_id', $tenantIds)));
            })
            ->orderByDesc('start_date')
            ->orderByDesc('id');
    }

    /** @return iterable<LeaseRow> */
    public function rows(User $user, array $filters): iterable
    {
        foreach ($this->query($user, $filters)->lazy(self::CHUNK)->chunk(self::CHUNK) as $chunk) {
            yield from $this->parties->rows($chunk);
        }
    }

    public function count(User $user, array $filters): int
    {
        return $this->query($user, $filters)->toBase()->getCountForPagination();
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Contract number', fn (LeaseRow $row) => $row->lease->contract_number),
            ExportColumn::make('Status', fn (LeaseRow $row) => $row->lease->status),
            ExportColumn::make('Tenant', fn (LeaseRow $row) => $row->tenantName),
            ExportColumn::make('Building', fn (LeaseRow $row) => $row->buildingName),
            ExportColumn::make('Unit', fn (LeaseRow $row) => $row->unitLabel),
            ExportColumn::make('Billing cycle', fn (LeaseRow $row) => $row->lease->billing_cycle),
            ExportColumn::make('Start date', fn (LeaseRow $row) => ExportCell::date($row->lease->start_date)),
            ExportColumn::make('End date', fn (LeaseRow $row) => ExportCell::date($row->lease->end_date)),
            ExportColumn::make('Rent per period', fn (LeaseRow $row) => $row->lease->rent_amount_kobo),
            ExportColumn::make('Deposit', fn (LeaseRow $row) => $row->lease->deposit_kobo),
            ExportColumn::make('Due days after period start', fn (LeaseRow $row) => $row->lease->due_day_offset),
            ExportColumn::make('Paid until', fn (LeaseRow $row) => ExportCell::date($row->lease->paid_until)),
            ExportColumn::make('Next billing date', fn (LeaseRow $row) => ExportCell::date($row->lease->next_billing_date)),
            ExportColumn::make('Auto-renew', fn (LeaseRow $row) => $row->lease->auto_renew),
            ExportColumn::make('Renewal of', fn (LeaseRow $row) => $row->renewalOfContract),
            ExportColumn::make('Unit held until', fn (LeaseRow $row) => ExportCell::date($row->lease->reserved_until)),
            ExportColumn::make('Activated on', fn (LeaseRow $row) => $row->lease->activated_at),
            ExportColumn::make('Ended on', fn (LeaseRow $row) => $row->lease->terminated_at ?? $row->lease->expired_at),
            ExportColumn::make('Reason ended', fn (LeaseRow $row) => $row->lease->termination_reason),
            ExportColumn::make('Added on', fn (LeaseRow $row) => $row->lease->created_at),
        ];
    }
}
