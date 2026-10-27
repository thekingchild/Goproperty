<?php

declare(strict_types=1);

namespace Modules\Properties\Lists;

use App\Exports\ExportColumn;
use App\Exports\ListFilters;
use App\Exports\QueryListExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Properties\Assignments\AgentNarrowing;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Enums\UnitType;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;

/**
 * The units list (/o/{slug}/units, Livewire\UnitIndex) and its export
 * (P02-07): every unit across the member's buildings (an assigned agent:
 * theirs only, AgentNarrowing), filtered by building, status and type, with
 * the search over the unit label and the building's name; by building name,
 * then label with "Flat 2" before "Flat 10". A building page's status
 * board exports through it too (the building and its status filter).
 * The export's headers are the units import's, plus the status.
 *
 * @extends QueryListExport<Unit>
 */
final class UnitList extends QueryListExport
{
    public function __construct(private readonly AgentNarrowing $narrowing) {}

    public function key(): string
    {
        return 'units';
    }

    public function title(): string
    {
        return 'Units';
    }

    public function filters(array $input): array
    {
        return ListFilters::set([
            'search' => ListFilters::search($input['search'] ?? null),
            'building' => ListFilters::id($input['building'] ?? null),
            'status' => ListFilters::option($input['status'] ?? null, UnitStatus::values()),
            'type' => ListFilters::option($input['type'] ?? null, UnitType::values()),
        ]);
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', Unit::class);
    }

    public function query(User $user, array $filters): Builder
    {
        $search = is_string($filters['search'] ?? null) ? $filters['search'] : null;
        $building = is_int($filters['building'] ?? null) ? $filters['building'] : null;
        $status = UnitStatus::tryFrom((string) ($filters['status'] ?? ''));
        $type = UnitType::tryFrom((string) ($filters['type'] ?? ''));

        return $this->narrowing->units(Unit::query(), $user)
            // Not the units of a building an import rollback removed.
            ->whereHas('property')
            ->when($building !== null, fn (Builder $query) => $query->where('property_id', $building))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($type !== null, fn (Builder $query) => $query->where('unit_type', $type))
            ->when($search !== null, function (Builder $query) use ($search): void {
                $like = ListFilters::like((string) $search);

                $query->where(fn (Builder $query) => $query
                    ->where('unit_label', 'like', $like)
                    ->orWhereHas('property', fn (Builder $building) => $building->where('name', 'like', $like)));
            })
            // By building name, then label with "Flat 2" before "Flat 10".
            ->orderBy(Property::query()->select('name')->whereColumn('properties.id', 'units.property_id'))
            ->orderBy('property_id')
            ->orderByRaw('CHAR_LENGTH(units.unit_label)')
            ->orderBy('unit_label')
            ->orderBy('id');
    }

    protected function exportQuery(User $user, array $filters): Builder
    {
        return $this->query($user, $filters)->with('property');
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Your ID', fn (Unit $unit) => $unit->external_ref),
            ExportColumn::make('Building', fn (Unit $unit) => $unit->property?->name),
            ExportColumn::make('Unit', fn (Unit $unit) => $unit->unit_label),
            ExportColumn::make('Type', fn (Unit $unit) => $unit->unit_type),
            ExportColumn::make('Bedrooms', fn (Unit $unit) => $unit->bedrooms),
            ExportColumn::make('Bathrooms', fn (Unit $unit) => $unit->bathrooms),
            ExportColumn::make('Size (sq m)', fn (Unit $unit) => $unit->size_sqm),
            ExportColumn::make('Floor', fn (Unit $unit) => $unit->floor),
            ExportColumn::make('Rent per year', fn (Unit $unit) => $unit->base_rent_kobo),
            ExportColumn::make('Service charge per year', fn (Unit $unit) => $unit->service_charge_kobo),
            ExportColumn::make('Status', fn (Unit $unit) => $unit->status),
            ExportColumn::make('Description', fn (Unit $unit) => $unit->description),
            ExportColumn::make('Building archived', fn (Unit $unit): bool => $unit->property instanceof Property && $unit->property->isArchived()),
            ExportColumn::make('Added on', fn (Unit $unit) => $unit->created_at),
        ];
    }
}
