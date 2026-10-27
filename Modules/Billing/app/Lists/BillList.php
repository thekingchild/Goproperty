<?php

declare(strict_types=1);

namespace Modules\Billing\Lists;

use App\Exports\ExportCell;
use App\Exports\ExportColumn;
use App\Exports\ListExport;
use App\Exports\ListFilters;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Billing\Bills\PaymentReferences;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Models\Bill;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * The bills list (/o/{slug}/bills, Livewire\BillIndex) and its export
 * (P02-07, AGENT.md § 5): the organization's bills in the member's
 * buildings (an assigned agent: theirs only, through
 * Modules\Properties\Contracts\BuildingAccess), filtered by status, type,
 * building, lease and tenant, with a search over the bill number, the
 * payment reference and the tenant's name, phone or email
 * (Modules\Tenants\Contracts\TenantDirectory); latest due date first.
 * Rows carry the tenant, unit and building names (BillParties). No contact
 * details are exported (names only), so staff may export it while
 * impersonating, audited.
 */
final class BillList implements ListExport
{
    public const int CHUNK = 500;

    public function __construct(
        private readonly BuildingAccess $access,
        private readonly TenantDirectory $tenants,
        private readonly BillParties $parties,
    ) {}

    public function key(): string
    {
        return 'bills';
    }

    public function title(): string
    {
        return 'Bills';
    }

    public function filters(array $input): array
    {
        return ListFilters::set([
            'search' => ListFilters::search($input['search'] ?? null),
            'status' => ListFilters::option($input['status'] ?? null, BillStatus::values()),
            'type' => ListFilters::option($input['type'] ?? null, BillType::values()),
            'building' => ListFilters::id($input['building'] ?? null),
            'lease' => ListFilters::id($input['lease'] ?? null),
            'tenant' => ListFilters::id($input['tenant'] ?? null),
        ]);
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', Bill::class);
    }

    /**
     * The list's rows: organization scope, narrowing, filters, search,
     * latest due date first.
     *
     * @param  array<string, string|int|bool>  $filters  cleaned
     * @return Builder<Bill>
     */
    public function query(User $user, array $filters): Builder
    {
        $search = is_string($filters['search'] ?? null) ? $filters['search'] : null;
        $status = BillStatus::tryFrom((string) ($filters['status'] ?? ''));
        $type = BillType::tryFrom((string) ($filters['type'] ?? ''));
        $limited = $this->access->limitedTo($user);

        return Bill::query()
            ->when($limited !== null, fn (Builder $query) => $query->whereIn('property_id', $limited ?? []))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type))
            ->when(is_int($filters['building'] ?? null), fn (Builder $query) => $query->where('property_id', $filters['building']))
            ->when(is_int($filters['lease'] ?? null), fn (Builder $query) => $query->where('lease_id', $filters['lease']))
            ->when(is_int($filters['tenant'] ?? null), fn (Builder $query) => $query->where('tenant_profile_id', $filters['tenant']))
            ->when($search !== null, function (Builder $query) use ($search): void {
                $tenantIds = $this->tenants->search((string) $search);
                $reference = PaymentReferences::normalize((string) $search);

                $query->where(fn (Builder $query) => $query
                    ->where('number', 'like', ListFilters::like((string) $search))
                    ->when($reference !== null, fn (Builder $query) => $query->orWhere('payment_reference', $reference))
                    ->when($tenantIds !== [], fn (Builder $query) => $query->orWhereIn('tenant_profile_id', $tenantIds)));
            })
            ->orderByDesc('due_date')
            ->orderByDesc('id');
    }

    /** @return iterable<BillRow> */
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
            ExportColumn::make('Bill number', fn (BillRow $row) => $row->bill->number),
            ExportColumn::make('Payment reference', fn (BillRow $row) => $row->bill->payment_reference),
            ExportColumn::make('Type', fn (BillRow $row) => $row->bill->type),
            ExportColumn::make('Status', fn (BillRow $row) => $row->bill->status),
            ExportColumn::make('Tenant', fn (BillRow $row) => $row->tenantName),
            ExportColumn::make('Building', fn (BillRow $row) => $row->buildingName),
            ExportColumn::make('Unit', fn (BillRow $row) => $row->unitLabel),
            ExportColumn::make('Description', fn (BillRow $row) => $row->bill->description),
            ExportColumn::make('Period start', fn (BillRow $row) => ExportCell::date($row->bill->period_start)),
            ExportColumn::make('Period end', fn (BillRow $row) => ExportCell::date($row->bill->period_end)),
            ExportColumn::make('Due date', fn (BillRow $row) => ExportCell::date($row->bill->due_date)),
            ExportColumn::make('Amount', fn (BillRow $row) => $row->bill->amount_kobo),
            ExportColumn::make('Paid', fn (BillRow $row) => $row->bill->paid_kobo),
            ExportColumn::make('Outstanding', fn (BillRow $row) => $row->bill->outstanding()),
            ExportColumn::make('Issued on', fn (BillRow $row) => $row->bill->issued_at),
            ExportColumn::make('Voided on', fn (BillRow $row) => $row->bill->voided_at),
            ExportColumn::make('Void reason', fn (BillRow $row) => $row->bill->void_reason),
        ];
    }
}
