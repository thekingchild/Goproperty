<?php

declare(strict_types=1);

namespace Modules\Properties\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Media\MediaUrls;
use App\Models\User;
use App\Support\NigerianStates;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Properties\Assignments\AgentNarrowing;
use Modules\Properties\Buildings\Properties;
use Modules\Properties\Enums\PropertyType;
use Modules\Properties\Lists\PropertyList;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;

/**
 * Buildings, /o/{organization}/buildings: the organization's buildings
 * (DATABASE_SCHEMA.md § 3), paginated on the server, with a debounced
 * search over name, address, landmark and LGA, filters by type, state and
 * owner, and an Active / Archived switch, all kept in the URL. Members with
 * `properties.view` see the list; with `properties.portfolio` they add,
 * archive and restore buildings (a status change, never a delete).
 *
 * An agent assigned to buildings sees only those (P02-03, AgentNarrowing):
 * the list, the owner filter and the actions' lookups are all limited.
 * The rows come from Lists\PropertyList, which also exports them (P02-07).
 *
 * Runs inside the `organization` middleware, replayed for Livewire updates:
 * every request has the organization's context and the component refuses
 * any other. Every action checks PropertyPolicy (the writer checks again).
 * Thumbnails are signed media links, made after the page's policy check.
 */
#[Layout('layouts::app')]
#[Title('Buildings')]
final class PropertyIndex extends Component
{
    use WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    #[Locked]
    public int $organizationId;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: false)]
    public bool $archived = false;

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $state = '';

    #[Url(except: '')]
    public string $owner = '';

    #[Locked]
    public ?string $notice = null;

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
        $this->authorize('viewAny', Property::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'archived', 'type', 'state', 'owner'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'type', 'state', 'owner');
        $this->resetPage();
    }

    public function archive(int $propertyId, Properties $properties, AgentNarrowing $narrowing): void
    {
        $property = $narrowing->buildings(Property::query(), $this->user())->findOrFail($propertyId);
        $this->authorize('delete', $property);

        // Refused (ValidationException, key `archive`) while a lease holds one of its units.
        $properties->archive($this->user(), $property);
        $this->notice = __(':name was archived. Find it under Archived to restore it.', ['name' => $property->name]);
    }

    public function restore(int $propertyId, Properties $properties, AgentNarrowing $narrowing): void
    {
        $property = $narrowing->buildings(Property::query(), $this->user())->findOrFail($propertyId);
        $this->authorize('restore', $property);

        $properties->restore($this->user(), $property);
        $this->notice = __(':name was restored.', ['name' => $property->name]);
    }

    public function render(OrganizationContext $context, MediaUrls $urls, AgentNarrowing $narrowing, PropertyList $list): View
    {
        $user = $this->user();
        $filters = $list->filters($this->listExports()['buildings']);

        $properties = PropertyList::withUnitCounts($list->query($user, $filters))
            ->with(['owner', 'coverPhoto'])
            ->paginate(self::PER_PAGE);

        $thumbnails = [];

        foreach ($properties as $property) {
            if ($property->coverPhoto !== null) {
                $thumbnails[$property->id] = $urls->preview($property->coverPhoto, 'thumb');
            }
        }

        $filtered = array_diff_key($filters, ['archived' => true]) !== [];
        // Every row of a page has the same status, so one check answers for all.
        $sample = $properties->first();

        return view('properties::livewire.property-index', [
            'organization' => $context->get(),
            'properties' => $properties,
            'thumbnails' => $thumbnails,
            'filtered' => $filtered,
            'canAdd' => $user->can('create', Property::class),
            'canArchive' => $sample instanceof Property && ! $this->archived && $user->can('delete', $sample),
            'canRestore' => $sample instanceof Property && $this->archived && $user->can('restore', $sample),
            'types' => PropertyType::cases(),
            'states' => NigerianStates::ALL,
            // Owners who own a building the member sees, for the filter (archived owners too).
            'owners' => OwnerProfile::query()->withTrashed()
                ->whereHas('properties', fn (Builder $buildings) => $narrowing->buildings($buildings, $user))
                ->orderBy('display_name')->pluck('display_name', 'id')->all(),
        ]);
    }

    /** The list on the page, with its filters (PropertyList; the Export button). */
    protected function listExports(): array
    {
        return ['buildings' => [
            'search' => $this->search,
            'archived' => $this->archived,
            'type' => $this->type,
            'state' => $this->state,
            'owner' => $this->owner,
        ]];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
