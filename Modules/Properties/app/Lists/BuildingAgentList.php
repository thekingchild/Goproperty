<?php

declare(strict_types=1);

namespace Modules\Properties\Lists;

use App\Exports\ExportColumn;
use App\Exports\ListExport;
use App\Exports\ListFilters;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\Properties\Assignments\AgentAssignments;
use Modules\Properties\Models\Property;

/**
 * A building's agents (the Agents section of /o/{slug}/buildings/{slug},
 * Livewire\PropertyShow; P02-03 assignments) and their export (P02-07):
 * the members assigned to the building, by name, with whether the
 * assignment limits what they see, exactly as the page lists them
 * (AgentAssignments::agentsOf). The building is looked up in the
 * organization and must be one the member sees (PropertyPolicy::
 * exportAgents is `view`: a narrowed agent's other buildings answer 404).
 * A building has a handful of agents, so they are read at once.
 */
final readonly class BuildingAgentList implements ListExport
{
    public function __construct(private AgentAssignments $assignments) {}

    public function key(): string
    {
        return 'building_agents';
    }

    public function title(): string
    {
        return 'Building agents';
    }

    public function filters(array $input): array
    {
        return ListFilters::set(['building' => ListFilters::id($input['building'] ?? null)]);
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('exportAgents', $this->building($filters));
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Building', fn (array $row): string => $row['building']),
            ExportColumn::make('Agent', fn (array $row): string => $row['user']->name),
            ExportColumn::make('Sees', fn (array $row): string => $row['limited'] ? 'Only the buildings they are assigned to' : 'Every building (owner or administrator)'),
        ];
    }

    /** @return list<array{building: string, user: User, limited: bool}> */
    public function rows(User $user, array $filters): array
    {
        $building = $this->building($filters);

        return array_map(static fn (array $row): array => ['building' => $building->name] + $row, $this->assignments->agentsOf($building));
    }

    public function count(User $user, array $filters): int
    {
        return count($this->assignments->agentsOf($this->building($filters)));
    }

    /** @param  array<string, string|int|bool>  $filters */
    private function building(array $filters): Property
    {
        return Property::query()->findOrFail(is_int($filters['building'] ?? null) ? $filters['building'] : 0);
    }
}
