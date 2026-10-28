<?php

declare(strict_types=1);

namespace Modules\Handovers\Lists;

use App\Exports\ExportColumn;
use App\Exports\QueryListExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Handovers\Models\ManagementHandover;

/**
 * "Handovers you offered" on /o/{slug}/handovers (Livewire\HandoverIndex)
 * and its export (P02-07): this organization's offers of its buildings,
 * newest first (the page shows the latest PAGE_LIMIT, the export all).
 * Owners only, never while staff impersonate
 * (ManagementHandoverPolicy::export).
 *
 * @extends QueryListExport<ManagementHandover>
 */
final class HandoversMadeList extends QueryListExport
{
    public const int PAGE_LIMIT = 20;

    public function key(): string
    {
        return 'handovers_made';
    }

    public function title(): string
    {
        return 'Handovers offered';
    }

    public function filters(array $input): array
    {
        return [];
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', ManagementHandover::class);
    }

    public function query(User $user, array $filters): Builder
    {
        return ManagementHandover::query()->with(['toOrganization', 'initiatedBy', 'respondedBy', 'buildings'])->orderByDesc('id');
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Offered to', fn (ManagementHandover $handover) => $handover->toOrganization->name),
            ...HandoverColumns::common(),
        ];
    }
}
