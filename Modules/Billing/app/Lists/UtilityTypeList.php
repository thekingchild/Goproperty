<?php

declare(strict_types=1);

namespace Modules\Billing\Lists;

use App\Exports\ExportColumn;
use App\Exports\ListFilters;
use App\Exports\QueryListExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Billing\Enums\ChargingMode;
use Modules\Billing\Models\UtilityType;

/**
 * The utility types list (/o/{slug}/utilities, Livewire\UtilityTypeIndex)
 * and its export (P02-07, AGENT.md § 5; P03-06a): the organization's
 * utilities, active first then by name, with how many units are charged
 * each (switched-on settings), filtered by status (active or archived) and
 * charging mode, with a search over the name and code. Organization-wide:
 * not narrowed. No contact details.
 *
 * @extends QueryListExport<UtilityType>
 */
final class UtilityTypeList extends QueryListExport
{
    public const string ACTIVE = 'active';

    public const string ARCHIVED = 'archived';

    public function key(): string
    {
        return 'utility_types';
    }

    public function title(): string
    {
        return 'Utility types';
    }

    public function filters(array $input): array
    {
        return ListFilters::set([
            'search' => ListFilters::search($input['search'] ?? null),
            'status' => ListFilters::option($input['status'] ?? null, [self::ACTIVE, self::ARCHIVED]),
            'charging' => ListFilters::option($input['charging'] ?? null, ChargingMode::values()),
        ]);
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', UtilityType::class);
    }

    public function query(User $user, array $filters): Builder
    {
        $search = is_string($filters['search'] ?? null) ? $filters['search'] : null;
        $status = $filters['status'] ?? null;
        $charging = ChargingMode::tryFrom((string) ($filters['charging'] ?? ''));

        return UtilityType::query()
            ->withCount(['settings as units_charged' => fn (Builder $query) => $query->where('active', true)])
            ->when($status === self::ACTIVE, fn (Builder $query) => $query->where('active', true))
            ->when($status === self::ARCHIVED, fn (Builder $query) => $query->where('active', false))
            ->when($charging !== null, fn (Builder $query) => $query->where('charging_mode', $charging))
            ->when($search !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', ListFilters::like((string) $search))
                ->orWhere('code', 'like', ListFilters::like((string) $search))))
            ->orderByDesc('active')
            ->orderBy('name')
            ->orderBy('id');
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Name', fn (UtilityType $type) => $type->name),
            ExportColumn::make('Code', fn (UtilityType $type) => $type->code),
            ExportColumn::make('Charging', fn (UtilityType $type) => $type->charging_mode->shortLabel()),
            ExportColumn::make('Unit of measure', fn (UtilityType $type) => $type->unit_of_measure?->label()),
            ExportColumn::make('Default amount', fn (UtilityType $type) => $type->default_amount_kobo),
            ExportColumn::make('Default cycle', fn (UtilityType $type) => $type->default_cycle),
            ExportColumn::make('Units charged', fn (UtilityType $type) => (int) $type->getAttribute('units_charged')),
            ExportColumn::make('Status', fn (UtilityType $type) => $type->active ? 'Active' : 'Archived'),
        ];
    }
}
