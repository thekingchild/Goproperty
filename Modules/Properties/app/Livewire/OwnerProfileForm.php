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
use Modules\Properties\Enums\OwnerIdType;
use Modules\Properties\Enums\OwnerType;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;
use Modules\Properties\Owners\OwnerProfiles;

/**
 * Add an owner (/o/{organization}/owners/create) or open one
 * (/o/{organization}/owners/{owner}): a person (first and last name) or a
 * company, contact details, address, and an ID number (NIN or CAC number).
 * Members with `owners.view` open an owner read-only; `owners.manage` adds
 * and saves (OwnerProfilePolicy, checked again by OwnerProfiles).
 *
 * An owner's page also lists the owner's buildings (P02-01b) for members
 * who may see buildings; an agent limited to some buildings sees only those
 * among them, and only the owners of their buildings (P02-03).
 *
 * The ID number on file is never sent to the browser: the page shows it
 * masked, and an empty field keeps it. The owner id is locked and loaded
 * through the organization scope on every save, so another organization's
 * owner answers 404.
 */
#[Layout('layouts::app')]
#[Title('Owner')]
final class OwnerProfileForm extends Component
{
    /** Buildings listed on an owner's page; the rest are behind a filtered link. */
    public const int BUILDINGS_SHOWN = 20;

    #[Locked]
    public int $organizationId;

    #[Locked]
    public ?int $ownerId = null;

    public string $type = 'person';

    public string $firstName = '';

    public string $lastName = '';

    public string $companyName = '';

    public string $email = '';

    public string $phone = '';

    public string $address = '';

    public string $state = '';

    public string $lga = '';

    public string $idType = 'none';

    /** A new ID number; empty keeps the one on file. */
    public string $idValue = '';

    public string $notes = '';

    #[Locked]
    public ?string $status = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, ?OwnerProfile $owner = null): void
    {
        $this->organizationId = $context->id();

        if ($owner === null) {
            $this->authorize('create', OwnerProfile::class);

            return;
        }

        $this->authorize('view', $owner);
        $this->ownerId = $owner->id;
        $this->fillFrom($owner);
    }

    public function save(OwnerProfiles $owners): void
    {
        $input = [
            'type' => $this->type,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'companyName' => $this->companyName,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'state' => $this->state,
            'lga' => $this->lga,
            'idType' => $this->idType,
            'idValue' => $this->idValue,
            'notes' => $this->notes,
        ];

        if ($this->ownerId === null) {
            $this->authorize('create', OwnerProfile::class);
            $owner = $owners->create($this->user(), $input);

            session()->flash('owner-status', __(':name was added.', ['name' => $owner->display_name]));
            $this->redirectRoute('workspace.owners.edit', ['organization' => $this->organizationSlug(), 'owner' => $owner->id], navigate: true);

            return;
        }

        $owner = $this->owner();
        $this->authorize('update', $owner);
        $owner = $owners->update($this->user(), $owner, $input);

        $this->fillFrom($owner);
        $this->status = __('Saved.');
    }

    public function render(OrganizationContext $context, AgentNarrowing $narrowing): View
    {
        $owner = $this->ownerId !== null ? $this->owner() : null;

        if ($owner !== null) {
            // Checked on every render: an agent no longer on any of this
            // owner's buildings sees the owner no more (404).
            $this->authorize('view', $owner);
        }

        return view('properties::livewire.owner-profile-form', [
            'organization' => $context->get(),
            'owner' => $owner,
            'canEdit' => $owner === null ? $this->user()->can('create', OwnerProfile::class) : $this->user()->can('update', $owner),
            'maskedId' => $owner?->maskedId(),
            'storedIdType' => $owner?->id_type,
            'types' => OwnerType::cases(),
            'idTypes' => OwnerIdType::cases(),
            'states' => NigerianStates::ALL,
            'flash' => session('owner-status'),
            // The owner's buildings (P02-01b), for members who may see buildings.
            'buildings' => $owner !== null && $this->user()->can('viewAny', Property::class)
                ? $narrowing->buildings($owner->properties()->getQuery(), $this->user())->orderBy('status')->orderBy('name')->limit(self::BUILDINGS_SHOWN)->get()
                : null,
            'buildingCount' => $owner !== null ? $narrowing->buildings($owner->properties()->getQuery(), $this->user())->count() : 0,
        ]);
    }

    private function owner(): OwnerProfile
    {
        return OwnerProfile::query()->findOrFail($this->ownerId);
    }

    private function fillFrom(OwnerProfile $owner): void
    {
        $this->type = $owner->type->value;
        $this->firstName = $owner->first_name ?? '';
        $this->lastName = $owner->last_name ?? '';
        $this->companyName = $owner->company_name ?? '';
        $this->email = $owner->email ?? '';
        $this->phone = $owner->phone ?? '';
        $this->address = $owner->address ?? '';
        $this->state = $owner->state ?? '';
        $this->lga = $owner->lga ?? '';
        $this->idType = $owner->id_type->value;
        $this->idValue = '';
        $this->notes = $owner->notes ?? '';
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
