<?php

declare(strict_types=1);

namespace Modules\Properties\Livewire;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Enums\UnitType;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\Properties\Units\CreateUnitsInBulk;

/**
 * Add several units with the same details at once
 * (/o/{organization}/buildings/{slug}/units/bulk): a label pattern with
 * {n} and a first number ("Flat {n}" from 1, ten units → Flat 1 … Flat 10),
 * previewed as you type. `properties.portfolio` only (UnitPolicy::create,
 * checked again by Units\CreateUnitsInBulk). All or nothing: when a label
 * is already in the building, nothing is added and each such label is
 * named.
 */
#[Layout('layouts::app')]
#[Title('Add units')]
final class UnitBulkForm extends Component
{
    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $propertyId;

    public string $pattern = 'Flat {n}';

    public string $start = '1';

    public string $count = '10';

    public string $unitType = 'apartment';

    public string $bedrooms = '';

    public string $bathrooms = '';

    public string $sizeSqm = '';

    public string $floor = '';

    public string $baseRent = '';

    public string $serviceCharge = '';

    public string $status = 'vacant';

    public string $description = '';

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, Property $property): void
    {
        $this->organizationId = $context->id();
        $this->authorize('create', [Unit::class, $property]);
        $this->propertyId = $property->id;
    }

    public function save(CreateUnitsInBulk $bulk): void
    {
        $property = $this->property();
        $this->authorize('create', [Unit::class, $property]);

        $added = $bulk->handle($this->user(), $property, [
            'pattern' => $this->pattern,
            'start' => $this->start,
            'count' => $this->count,
            'unitType' => $this->unitType,
            'bedrooms' => $this->bedrooms,
            'bathrooms' => $this->bathrooms,
            'sizeSqm' => $this->sizeSqm,
            'floor' => $this->floor,
            'baseRent' => $this->baseRent,
            'serviceCharge' => $this->serviceCharge,
            'status' => $this->status,
            'description' => $this->description,
        ]);

        session()->flash('building-status', trans_choice(':count unit was added to :building.|:count units were added to :building.', count($added), ['count' => count($added), 'building' => $property->name]));

        $this->redirectRoute('workspace.buildings.show', ['organization' => app(OrganizationContext::class)->get()->slug, 'property' => $property->slug], navigate: true);
    }

    public function render(OrganizationContext $context): View
    {
        return view('properties::livewire.unit-bulk-form', [
            'organization' => $context->get(),
            'property' => $this->property(),
            'types' => UnitType::cases(),
            'statuses' => UnitStatus::manual(),
            'preview' => $this->preview(),
            'maxUnits' => CreateUnitsInBulk::MAX_UNITS,
        ]);
    }

    /**
     * The first and last labels the pattern gives, for the page; null while
     * the pattern or numbers are not usable yet.
     *
     * @return array{first: string, last: string, count: int}|null
     */
    private function preview(): ?array
    {
        $start = trim($this->start);
        $count = trim($this->count);

        if (substr_count($this->pattern, CreateUnitsInBulk::PLACEHOLDER) !== 1 || ! ctype_digit($start) || ! ctype_digit($count)
            || (int) $count < 1 || (int) $count > CreateUnitsInBulk::MAX_UNITS || (int) $start > CreateUnitsInBulk::MAX_START) {
            return null;
        }

        $labels = CreateUnitsInBulk::labels(trim($this->pattern), (int) $start, (int) $count);

        return ['first' => $labels[0], 'last' => $labels[count($labels) - 1], 'count' => count($labels)];
    }

    private function property(): Property
    {
        return Property::query()->findOrFail($this->propertyId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
