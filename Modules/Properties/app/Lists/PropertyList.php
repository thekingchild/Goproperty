<?php

declare(strict_types=1);

namespace Modules\Properties\Lists;

use App\Exports\ExportColumn;
use App\Exports\ListFilters;
use App\Exports\QueryListExport;
use App\Models\User;
use App\Support\NigerianStates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Properties\Assignments\AgentNarrowing;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Enums\PropertyType;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;

/**
 * The buildings list (/o/{slug}/buildings, Livewire\PropertyIndex) and its
 * export (P02-07): the member's buildings (an assigned agent: theirs only,
 * AgentNarrowing), active or archived, filtered by type, state and owner,
 * with the search over name, address, landmark and LGA, by name. The
 * export's headers are the buildings import's, plus unit and occupancy
 * counts.
 *
 * @extends QueryListExport<Property>
 */
final class PropertyList extends QueryListExport
{
    public function __construct(private readonly AgentNarrowing $narrowing) {}

    public function key(): string
    {
        return 'buildings';
    }

    public function title(): string
    {
        return 'Buildings';
    }

    public function filters(array $input): array
    {
        return ListFilters::set([
            'search' => ListFilters::search($input['search'] ?? null),
            'archived' => ListFilters::flag($input['archived'] ?? false),
            'type' => ListFilters::option($input['type'] ?? null, PropertyType::values()),
            'state' => ListFilters::option($input['state'] ?? null, NigerianStates::ALL),
            'owner' => ListFilters::id($input['owner'] ?? null),
        ]);
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', Property::class);
    }

    public function query(User $user, array $filters): Builder
    {
        $search = is_string($filters['search'] ?? null) ? $filters['search'] : null;
        $type = PropertyType::tryFrom((string) ($filters['type'] ?? ''));
        $state = $filters['state'] ?? null;
        $owner = is_int($filters['owner'] ?? null) ? $filters['owner'] : null;

        return $this->narrowing->buildings(Property::query(), $user)
            ->where('status', ($filters['archived'] ?? false) === true ? PropertyStatus::Archived : PropertyStatus::Active)
            ->when($type !== null, fn (Builder $query) => $query->where('property_type', $type))
            ->when(is_string($state), fn (Builder $query) => $query->where('state', $state))
            ->when($owner !== null, fn (Builder $query) => $query->where('owner_profile_id', $owner))
            ->when($search !== null, function (Builder $query) use ($search): void {
                $like = ListFilters::like((string) $search);

                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', $like)
                    ->orWhere('address', 'like', $like)
                    ->orWhere('landmark', 'like', $like)
                    ->orWhere('lga', 'like', $like));
            })
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * Unit and occupancy counts in the same query (no query per row).
     *
     * @param  Builder<Property>  $query
     * @return Builder<Property>
     */
    public static function withUnitCounts(Builder $query): Builder
    {
        return $query->withCount(['units', 'units as occupied_units_count' => fn (Builder $units) => $units->where('status', UnitStatus::Occupied)]);
    }

    protected function exportQuery(User $user, array $filters): Builder
    {
        return self::withUnitCounts($this->query($user, $filters))->with('owner');
    }

    public function columns(User $user): array
    {
        return array_values(array_filter([
            ExportColumn::make('Your ID', fn (Property $building) => $building->external_ref),
            ExportColumn::make('Building name', fn (Property $building) => $building->name),
            // As on the building's page: only for members who see owners.
            $user->can('viewAny', OwnerProfile::class)
                ? ExportColumn::make('Owner', fn (Property $building) => $building->owner instanceof OwnerProfile ? $building->owner->display_name : null)
                : null,
            ExportColumn::make('Type', fn (Property $building) => $building->property_type),
            ExportColumn::make('Address', fn (Property $building) => $building->address),
            ExportColumn::make('State', fn (Property $building) => $building->state),
            ExportColumn::make('LGA', fn (Property $building) => $building->lga),
            ExportColumn::make('Landmark', fn (Property $building) => $building->landmark),
            ExportColumn::make('Amenities', fn (Property $building) => implode(', ', $building->amenities ?? [])),
            ExportColumn::make('Year built', fn (Property $building) => $building->year_built),
            ExportColumn::make('Description', fn (Property $building) => $building->description),
            ExportColumn::make('Units', fn (Property $building): int => (int) $building->getAttribute('units_count')),
            ExportColumn::make('Occupied units', fn (Property $building): int => (int) $building->getAttribute('occupied_units_count')),
            ExportColumn::make('Status', fn (Property $building) => $building->status),
            ExportColumn::make('Added on', fn (Property $building) => $building->created_at),
        ]));
    }
}
