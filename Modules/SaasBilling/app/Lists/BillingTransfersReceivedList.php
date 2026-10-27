<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Lists;

use App\Exports\ExportColumn;
use App\Exports\ListExport;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\SaasBilling\Models\BillingTransferRequest;
use Modules\SaasBilling\Transfers\IncomingTransfers;

/**
 * "Offers to you" on /o/{slug}/billing-transfers (the invite inbox) and its
 * export (P02-07): offers other organizations made to this one, open ones
 * first, read through IncomingTransfers (the rows belong to the offering
 * organizations; only those addressed to this one are found). Owners
 * only, never while staff impersonate (BillingTransferRequestPolicy::
 * export).
 */
final readonly class BillingTransfersReceivedList implements ListExport
{
    public function __construct(private IncomingTransfers $incoming) {}

    public function key(): string
    {
        return 'billing_transfers_received';
    }

    public function title(): string
    {
        return 'Billing transfers received';
    }

    public function filters(array $input): array
    {
        return [];
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', BillingTransferRequest::class);
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Offered by organization', fn (BillingTransferRequest $transfer) => $transfer->organization->name),
            ...BillingTransferColumns::common(),
        ];
    }

    /** @return Collection<int, BillingTransferRequest> */
    public function rows(User $user, array $filters): Collection
    {
        return $this->incoming->all();
    }

    public function count(User $user, array $filters): int
    {
        return $this->incoming->all()->count();
    }
}
