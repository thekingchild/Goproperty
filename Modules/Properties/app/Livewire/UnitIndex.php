<?php

declare(strict_types=1);

namespace Modules\Properties\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Properties\Assignments\AgentNarrowing;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Enums\UnitType;
use Modules\Properties\Lists\UnitList;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;

/**
 * Units, /o/{organization}/units: every unit across the organization's
 * buildings (DATABASE_SCHEMA.md § 3; P02-03), paginated on the server (25
 * a page), with a debounced search over the unit label and the building's
 * name, and filters by building, status and type, all kept in the URL
 * (ARCHITECTURE.md § 13: server-side pagination, debounced search, no
 * N+1: one query for the page, its buildings eager loaded). Archived units
 * are on their building's page; units of an archived building are listed,
 * marked, read-only.
 *
 * Members with `properties.view` see it (UnitPolicy::viewAny); an agent
 * assigned to buildings sees only those buildings' units, and only those
 * buildings in the filter (AgentNarrowing). Editing happens on the unit's
 * own page, which checks UnitPolicy again. The rows come from
 * Lists\UnitList, which also exports them (P02-07, Export button).
 *
 * Runs inside the `organization` middleware, replayed for Livewire updates:
 * every request has the organization's context and the component refuses
 * any other.
 */
#[Layout('layouts::app')]
#[Title('Units')]
final class UnitIndex extends Component
{
    use WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    #[Locked]
    public int $organizationId;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** A building id, or empty for every building. */
    #[Url(except: '')]
    public string $building = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', Unit::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'building', 'status', 'type'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'building', 'status', 'type');
        $this->resetPage();
    }

    public function render(OrganizationContext $context, AgentNarrowing $narrowing, UnitList $list): View
    {
        $user = $this->user();
        $filters = $list->filters($this->listExports()['units']);
        $units = $list->query($user, $filters)->with('property')->paginate(self::PER_PAGE);

        // Whether units can be edited: the same answer for every unit of an
        // active building on the page (the list is already narrowed), so
        // one policy check rather than one per row.
        $sample = $units->getCollection()->first(static fn (Unit $unit): bool => $unit->property instanceof Property && ! $unit->property->isArchived());

        return view('properties::livewire.unit-index', [
            'organization' => $context->get(),
            'units' => $units,
            'filtered' => $filters !== [],
            'canEdit' => $sample instanceof Unit && $user->can('update', $sample),
            'buildings' => $narrowing->buildings(Property::query(), $user)->orderBy('name')->orderBy('id')->pluck('name', 'id')->all(),
            'statuses' => UnitStatus::cases(),
            'types' => UnitType::cases(),
        ]);
    }

    /** The list on the page, with its filters (UnitList; the Export button). */
    protected function listExports(): array
    {
        return ['units' => [
            'search' => $this->search,
            'building' => $this->building,
            'status' => $this->status,
            'type' => $this->type,
        ]];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
