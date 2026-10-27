<?php

declare(strict_types=1);

namespace Modules\Properties\Livewire;

use App\Models\User;
use App\Support\NigerianStates;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Properties\Assignments\AgentNarrowing;
use Modules\Properties\Buildings\Amenities;
use Modules\Properties\Buildings\Properties;
use Modules\Properties\Enums\PropertyType;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;

/**
 * Add a building (/o/{organization}/buildings/create) or edit one
 * (/o/{organization}/buildings/{slug}/edit): name, type, owner (picked from
 * the organization's owner profiles, or none when the organization owns
 * it), address, amenities and details. `properties.portfolio` adds;
 * `properties.manage` edits an active building (PropertyPolicy, checked
 * again by Buildings\Properties). Photos are added on the building's page.
 * The owner picker lists the owners the member may see (an agent limited to
 * some buildings sees those buildings' owners only, P02-03); the writer
 * refuses any other.
 *
 * The building id is locked and the building loaded through the
 * organization scope on every save, so another organization's building
 * answers 404.
 */
#[Layout('layouts::app')]
#[Title('Building')]
final class PropertyForm extends Component
{
    #[Locked]
    public int $organizationId;

    #[Locked]
    public ?int $propertyId = null;

    public string $name = '';

    public string $propertyType = 'block_of_flats';

    /** An owner profile id, or empty for "no owner on file". */
    public string $ownerProfileId = '';

    public string $address = '';

    public string $state = '';

    public string $lga = '';

    public string $landmark = '';

    public string $description = '';

    /** @var list<string> ticked suggestions (Amenities::SUGGESTED) */
    public array $amenities = [];

    /** Other amenities, comma separated. */
    public string $otherAmenities = '';

    public string $yearBuilt = '';

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, ?Property $property = null): void
    {
        $this->organizationId = $context->id();

        if ($property === null) {
            $this->authorize('create', Property::class);
            $owner = request()->query('owner');
            $this->ownerProfileId = is_string($owner) && ctype_digit($owner) && OwnerProfile::query()->whereKey((int) $owner)->exists() ? $owner : '';

            return;
        }

        $this->authorize('update', $property);
        $this->propertyId = $property->id;
        $this->fillFrom($property);
    }

    public function save(Properties $properties): void
    {
        $input = [
            'name' => $this->name,
            'propertyType' => $this->propertyType,
            'ownerProfileId' => $this->ownerProfileId,
            'address' => $this->address,
            'state' => $this->state,
            'lga' => $this->lga,
            'landmark' => $this->landmark,
            'description' => $this->description,
            'amenities' => $this->amenities,
            'otherAmenities' => $this->otherAmenities,
            'yearBuilt' => $this->yearBuilt,
        ];

        if ($this->propertyId === null) {
            $this->authorize('create', Property::class);
            $property = $properties->create($this->user(), $input);
            session()->flash('building-status', __(':name was added. Add photos and units next.', ['name' => $property->name]));
        } else {
            $property = $this->property();
            $this->authorize('update', $property);
            $property = $properties->update($this->user(), $property, $input);
            session()->flash('building-status', __('Saved.'));
        }

        $this->redirectRoute('workspace.buildings.show', ['organization' => $this->organizationSlug(), 'property' => $property->slug], navigate: true);
    }

    public function render(OrganizationContext $context, AgentNarrowing $narrowing): View
    {
        $property = $this->propertyId !== null ? $this->property() : null;
        $currentOwnerId = $property?->owner_profile_id;

        return view('properties::livewire.property-form', [
            'organization' => $context->get(),
            'property' => $property,
            'types' => PropertyType::cases(),
            'states' => NigerianStates::ALL,
            'suggestedAmenities' => Amenities::SUGGESTED,
            // Active owners, plus the building's own owner if it was archived since.
            'owners' => $narrowing->owners(OwnerProfile::query()->withTrashed(), $this->user())
                ->where(fn ($query) => $query->whereNull('deleted_at')->when($currentOwnerId !== null, fn ($query) => $query->orWhere('id', $currentOwnerId)))
                ->orderBy('display_name')
                ->get(['id', 'display_name', 'deleted_at']),
            'canAddOwner' => $this->user()->can('create', OwnerProfile::class),
        ]);
    }

    private function property(): Property
    {
        return Property::query()->findOrFail($this->propertyId);
    }

    private function fillFrom(Property $property): void
    {
        [$ticked, $other] = Amenities::split($property->amenities ?? []);

        $this->name = $property->name;
        $this->propertyType = $property->property_type->value;
        $this->ownerProfileId = $property->owner_profile_id !== null ? (string) $property->owner_profile_id : '';
        $this->address = $property->address;
        $this->state = $property->state;
        $this->lga = $property->lga ?? '';
        $this->landmark = $property->landmark ?? '';
        $this->description = $property->description ?? '';
        $this->amenities = $ticked;
        $this->otherAmenities = implode(', ', $other);
        $this->yearBuilt = $property->year_built !== null ? (string) $property->year_built : '';
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
