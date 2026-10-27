<?php

declare(strict_types=1);

namespace Modules\Payments\Lists;

use App\Exports\ExportColumn;
use App\Exports\ListExport;
use App\Exports\ListFilters;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Payments\Enums\ReceiptStatus;
use Modules\Payments\Models\Receipt;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * The receipts list (/o/{slug}/receipts, Livewire\ReceiptIndex) and its
 * export (P02-07, AGENT.md § 5; P03-10b): the organization's receipts of
 * the tenants the member sees (an agent assigned to buildings: their
 * tenants', TenantDirectory::narrowedIdsQuery), newest first, filtered by
 * status (valid, void requested — the approvers' queue —, voided) and
 * tenant, with a search over the receipt number and the tenant's name,
 * phone or email. Receipt numbers, tenant names, amounts and void reasons;
 * no contact details, so staff may export it while impersonating, audited
 * (PLATFORM_CONSOLE.md § 6).
 */
final class ReceiptList implements ListExport
{
    public const int CHUNK = 500;

    public function __construct(
        private readonly TenantDirectory $tenants,
        private readonly ReceiptRows $rows,
    ) {}

    public function key(): string
    {
        return 'receipts';
    }

    public function title(): string
    {
        return 'Receipts';
    }

    public function filters(array $input): array
    {
        return ListFilters::set([
            'search' => ListFilters::search($input['search'] ?? null),
            'status' => ListFilters::option($input['status'] ?? null, ReceiptStatus::values()),
            'tenant' => ListFilters::id($input['tenant'] ?? null),
        ]);
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', Receipt::class);
    }

    /**
     * @param  array<string, string|int|bool>  $filters  cleaned
     * @return Builder<Receipt>
     */
    public function query(User $user, array $filters): Builder
    {
        $search = is_string($filters['search'] ?? null) ? $filters['search'] : null;
        $status = ReceiptStatus::tryFrom((string) ($filters['status'] ?? ''));
        $visible = $this->tenants->narrowedIdsQuery($user);

        return Receipt::query()
            ->when($visible !== null, fn (Builder $query) => $query->whereIn('tenant_profile_id', $visible))
            ->when($status === ReceiptStatus::Valid, fn (Builder $query) => $query->whereNull('voided_at')->whereNull('void_requested_at'))
            ->when($status === ReceiptStatus::VoidRequested, fn (Builder $query) => $query->whereNull('voided_at')->whereNotNull('void_requested_at'))
            ->when($status === ReceiptStatus::Voided, fn (Builder $query) => $query->whereNotNull('voided_at'))
            ->when(is_int($filters['tenant'] ?? null), fn (Builder $query) => $query->where('tenant_profile_id', $filters['tenant']))
            ->when($search !== null, function (Builder $query) use ($search): void {
                $tenantIds = $this->tenants->search((string) $search);
                $like = ListFilters::like((string) $search);

                $query->where(fn (Builder $query) => $query
                    ->where('number', 'like', $like)
                    ->when($tenantIds !== [], fn (Builder $query) => $query->orWhereIn('tenant_profile_id', $tenantIds)));
            })
            ->orderByDesc('issued_at')
            ->orderByDesc('id');
    }

    /** @return iterable<ReceiptRow> */
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
            ExportColumn::make('Receipt', fn (ReceiptRow $row) => $row->receipt->number),
            ExportColumn::make('Issued on', fn (ReceiptRow $row) => $row->receipt->issued_at),
            ExportColumn::make('Tenant', fn (ReceiptRow $row) => $row->tenantName),
            ExportColumn::make('Amount', fn (ReceiptRow $row) => $row->receipt->amount_kobo),
            ExportColumn::make('Method', fn (ReceiptRow $row) => $row->method),
            ExportColumn::make('Status', fn (ReceiptRow $row) => $row->receipt->status()),
            ExportColumn::make('Void asked by', fn (ReceiptRow $row) => $row->requestedBy),
            ExportColumn::make('Void approved by', fn (ReceiptRow $row) => $row->approvedBy),
            ExportColumn::make('Voided on', fn (ReceiptRow $row) => $row->receipt->voided_at),
            ExportColumn::make('Void reason', fn (ReceiptRow $row) => $row->receipt->void_reason ?? $row->receipt->void_request_reason),
            ExportColumn::make('Single approver', fn (ReceiptRow $row) => $row->receipt->isVoided() ? $row->receipt->void_single_approver : null),
        ];
    }
}
