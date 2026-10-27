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
use Modules\Properties\Units\UnitDetails;
use Modules\Properties\Units\Units;

/**
 * Add a unit to a building (/o/{organization}/buildings/{slug}/units/create)
 * or edit one (…/units/{unit}/edit), with archive on the edit page.
 * `properties.portfolio` adds and archives; `properties.manage` edits a
 * unit of an active building (UnitPolicy, checked again by Units\Units).
 * Rent and service charge are typed in naira and kept as kobo.
 *
 * The building and unit ids are locked and both loaded through the
 * organization scope on every request; a unit of another building (or
 * organization) answers 404.
 */
#[Layout('layouts::app')]
#[Title('Unit')]
final class UnitForm extends Component
{
    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $propertyId;

    #[Locked]
    public ?int $unitId = null;

    public string $unitLabel = '';

    public string $unitType = 'apartment';

    public string $bedrooms = '';

    public string $bathrooms = '';

    public string $sizeSqm = '';

    public string $floor = '';

    /** Yearly rent in naira, as typed ("1,500,000"). */
    public string $baseRent = '';

    /** Yearly service charge in naira, as typed; empty for none. */
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

    public function mount(OrganizationContext $context, Property $property, ?Unit $unit = null): void
    {
        $this->organizationId = $context->id();
        $this->propertyId = $property->id;

        if ($unit === null) {
            $this->authorize('create', [Unit::class, $property]);

            return;
        }

        abort_unless($unit->property_id === $property->id, 404);
        $this->authorize('update', $unit);
        $this->unitId = $unit->id;
        $this->fillFrom($unit);
    }

    public function save(Units $units): void
    {
        $property = $this->property();
        $input = [
            'unitLabel' => $this->unitLabel,
            'unitType' => $this->unitType,
            'bedrooms' => $this->bedrooms,
            'bathrooms' => $this->bathrooms,
            'sizeSqm' => $this->sizeSqm,
            'floor' => $this->floor,
            'baseRent' => $this->baseRent,
            'serviceCharge' => $this->serviceCharge,
            'status' => $this->status,
            'description' => $this->description,
        ];

        if ($this->unitId === null) {
            $this->authorize('create', [Unit::class, $property]);
            $unit = $units->create($this->user(), $property, $input);
            session()->flash('building-status', __(':label was added to :building.', ['label' => $unit->unit_label, 'building' => $property->name]));
        } else {
            $unit = $this->unit();
            $this->authorize('update', $unit);
            $unit = $units->update($this->user(), $unit, $input);
            session()->flash('building-status', __(':label was saved.', ['label' => $unit->unit_label]));
        }

        $this->redirectRoute('workspace.buildings.show', ['organization' => $this->organizationSlug(), 'property' => $property->slug], navigate: true);
    }

    public function archive(Units $units): void
    {
        $unit = $this->unit();
        $this->authorize('delete', $unit);

        // Refused (ValidationException, key `archive`) while a lease holds the unit.
        $units->archive($this->user(), $unit);
        session()->flash('building-status', __(':label was archived. It no longer counts towards your bill.', ['label' => $unit->unit_label]));

        $this->redirectRoute('workspace.buildings.show', ['organization' => $this->organizationSlug(), 'property' => $this->property()->slug], navigate: true);
    }

    public function render(OrganizationContext $context): View
    {
        $property = $this->property();
        $unit = $this->unitId !== null ? $this->unit() : null;
        $unit?->setRelation('property', $property);

        return view('properties::livewire.unit-form', [
            'organization' => $context->get(),
            'property' => $property,
            'unit' => $unit,
            'types' => UnitType::cases(),
            'statuses' => UnitStatus::manual(),
            'heldByLease' => $unit !== null && $unit->status->heldByLease(),
            'canArchive' => $unit !== null && ! $unit->status->heldByLease() && $this->user()->can('delete', $unit),
        ]);
    }

    private function property(): Property
    {
        return Property::query()->findOrFail($this->propertyId);
    }

    private function unit(): Unit
    {
        return Unit::query()->where('property_id', $this->propertyId)->findOrFail($this->unitId);
    }

    private function fillFrom(Unit $unit): void
    {
        $this->unitLabel = $unit->unit_label;
        $this->unitType = $unit->unit_type->value;
        $this->bedrooms = $unit->bedrooms !== null ? (string) $unit->bedrooms : '';
        $this->bathrooms = $unit->bathrooms !== null ? (string) $unit->bathrooms : '';
        $this->sizeSqm = $unit->size_sqm !== null ? (string) $unit->size_sqm : '';
        $this->floor = $unit->floor ?? '';
        $this->baseRent = UnitDetails::nairaInput($unit->base_rent_kobo);
        $this->serviceCharge = UnitDetails::nairaInput($unit->service_charge_kobo, emptyWhenZero: true);
        $this->status = $unit->status->value;
        $this->description = $unit->description ?? '';
    }

    private function organizationSlug(): string
    {
        return app(OrganizationContext::class)->get()->slug;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
