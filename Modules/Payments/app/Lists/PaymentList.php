<?php

declare(strict_types=1);

namespace Modules\Payments\Lists;

use App\Exports\ExportCell;
use App\Exports\ExportColumn;
use App\Exports\ListExport;
use App\Exports\ListFilters;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\Payment;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * The payments list (/o/{slug}/payments, Livewire\PaymentIndex) and its
 * export (P02-07, AGENT.md § 5): the organization's payments of the tenants
 * the member sees (an agent assigned to buildings: their tenants', through
 * Modules\Tenants\Contracts\TenantDirectory::narrowedIdsQuery), filtered by
 * status, method, source, tenant and bill, with a search over the tenant's
 * name, phone or email, the payer's name and the reference. The
 * confirmation queue is the list filtered to payments waiting for
 * confirmation, oldest paid first (first in, first confirmed); otherwise
 * the latest paid first. Names and amounts only, no contact details, so
 * staff may export it while impersonating, audited.
 */
final class PaymentList implements ListExport
{
    public const int CHUNK = 500;

    public function __construct(
        private readonly TenantDirectory $tenants,
        private readonly PaymentRows $rows,
    ) {}

    public function key(): string
    {
        return 'payments';
    }

    public function title(): string
    {
        return 'Payments';
    }

    public function filters(array $input): array
    {
        return ListFilters::set([
            'search' => ListFilters::search($input['search'] ?? null),
            'status' => ListFilters::option($input['status'] ?? null, PaymentStatus::values()),
            'method' => ListFilters::option($input['method'] ?? null, PaymentMethod::values()),
            'source' => ListFilters::option($input['source'] ?? null, PaymentSource::values()),
            'tenant' => ListFilters::id($input['tenant'] ?? null),
            'bill' => ListFilters::id($input['bill'] ?? null),
        ]);
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', Payment::class);
    }

    /**
     * The list's rows: organization scope, narrowing, filters, search, order.
     *
     * @param  array<string, string|int|bool>  $filters  cleaned
     * @return Builder<Payment>
     */
    public function query(User $user, array $filters): Builder
    {
        $search = is_string($filters['search'] ?? null) ? $filters['search'] : null;
        $status = PaymentStatus::tryFrom((string) ($filters['status'] ?? ''));
        $method = PaymentMethod::tryFrom((string) ($filters['method'] ?? ''));
        $source = PaymentSource::tryFrom((string) ($filters['source'] ?? ''));
        $visible = $this->tenants->narrowedIdsQuery($user);

        $query = Payment::query()
            ->when($visible !== null, fn (Builder $query) => $query->whereIn('tenant_profile_id', $visible))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($method !== null, fn (Builder $query) => $query->where('method', $method))
            ->when($source !== null, fn (Builder $query) => $query->where('source', $source))
            ->when(is_int($filters['tenant'] ?? null), fn (Builder $query) => $query->where('tenant_profile_id', $filters['tenant']))
            ->when(is_int($filters['bill'] ?? null), fn (Builder $query) => $query->where('bill_id', $filters['bill']))
            ->when($search !== null, function (Builder $query) use ($search): void {
                $tenantIds = $this->tenants->search((string) $search);
                $like = ListFilters::like((string) $search);

                $query->where(fn (Builder $query) => $query
                    ->where('payer_name', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->when($tenantIds !== [], fn (Builder $query) => $query->orWhereIn('tenant_profile_id', $tenantIds)));
            });

        // The confirmation queue: first in, first confirmed.
        return $status === PaymentStatus::PendingConfirmation
            ? $query->orderBy('paid_at')->orderBy('id')
            : $query->orderByDesc('paid_at')->orderByDesc('id');
    }

    /** @return iterable<PaymentRow> */
    public function rows(User $user, array $filters): iterable
    {
        foreach ($this->query($user, $filters)->lazy(self::CHUNK)->chunk(self::CHUNK) as $chunk) {
            yield from $this->rows->rows($chunk);
        }
    }

    public function count(User $user, array $filters): int
    {
        return $this->query($user, $filters)->toBase()->getCountForPagination();
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Paid on', fn (PaymentRow $row) => ExportCell::date($row->payment->paid_at)),
            ExportColumn::make('Tenant', fn (PaymentRow $row) => $row->tenantName),
            ExportColumn::make('Amount', fn (PaymentRow $row) => $row->payment->amount_kobo),
            ExportColumn::make('Method', fn (PaymentRow $row) => $row->payment->method),
            ExportColumn::make('Source', fn (PaymentRow $row) => $row->payment->source),
            ExportColumn::make('Status', fn (PaymentRow $row) => $row->payment->status),
            ExportColumn::make('Payer', fn (PaymentRow $row) => $row->payment->payer_name),
            ExportColumn::make('Reference', fn (PaymentRow $row) => $row->payment->reference),
            ExportColumn::make('Recorded on', fn (PaymentRow $row) => $row->payment->created_at),
            ExportColumn::make('Confirmed on', fn (PaymentRow $row) => $row->payment->confirmed_at),
            ExportColumn::make('Rejected on', fn (PaymentRow $row) => $row->payment->rejected_at),
            ExportColumn::make('Rejection reason', fn (PaymentRow $row) => $row->payment->rejection_reason),
        ];
    }
}
