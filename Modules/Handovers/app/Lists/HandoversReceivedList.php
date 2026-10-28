<?php

declare(strict_types=1);

namespace Modules\Handovers\Lists;

use App\Exports\ExportColumn;
use App\Exports\ListExport;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Handovers\Handovers\IncomingHandovers;
use Modules\Handovers\Models\ManagementHandover;

/**
 * "Handovers offered to you" on /o/{slug}/handovers (the inbox and the
 * buildings managed for landlords) and its export (P02-07): open ones
 * first, read through IncomingHandovers (the rows belong to the offering
 * organizations; only those addressed to this one are found). Owners
 * only, never while staff impersonate (ManagementHandoverPolicy::export).
 */
final readonly class HandoversReceivedList implements ListExport
{
    public function __construct(private IncomingHandovers $incoming) {}

    public function key(): string
    {
        return 'handovers_received';
    }

    public function title(): string
    {
        return 'Handovers received';
    }

    public function filters(array $input): array
    {
        return [];
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', ManagementHandover::class);
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Offered by organization', fn (ManagementHandover $handover) => $handover->organization->name),
            ...HandoverColumns::common(),
        ];
    }

    /** @return Collection<int, ManagementHandover> */
    public function rows(User $user, array $filters): Collection
    {
        return $this->incoming->all();
    }

    public function count(User $user, array $filters): int
    {
        return $this->incoming->all()->count();
    }
}
