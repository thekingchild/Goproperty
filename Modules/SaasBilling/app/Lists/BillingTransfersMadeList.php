<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Lists;

use App\Exports\ExportColumn;
use App\Exports\QueryListExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\SaasBilling\Models\BillingTransferRequest;

/**
 * "Offers you made" on /o/{slug}/billing-transfers (Livewire\
 * BillingTransfers) and its export (P02-07): this organization's offers
 * of its bill, newest first (the page shows the latest PAGE_LIMIT, the
 * export all). Owners only, never while staff impersonate
 * (BillingTransferRequestPolicy::export).
 *
 * @extends QueryListExport<BillingTransferRequest>
 */
final class BillingTransfersMadeList extends QueryListExport
{
    public const int PAGE_LIMIT = 20;

    public function key(): string
    {
        return 'billing_transfers_made';
    }

    public function title(): string
    {
        return 'Billing transfers made';
    }

    public function filters(array $input): array
    {
        return [];
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', BillingTransferRequest::class);
    }

    public function query(User $user, array $filters): Builder
    {
        return BillingTransferRequest::query()->with(['toOrganization', 'initiatedBy', 'respondedBy'])->orderByDesc('id');
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Offered to', fn (BillingTransferRequest $transfer) => $transfer->toOrganization->name),
            ...BillingTransferColumns::common(),
        ];
    }
}
