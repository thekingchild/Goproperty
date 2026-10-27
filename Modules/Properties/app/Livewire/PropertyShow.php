<?php

declare(strict_types=1);

namespace Modules\Properties\Livewire;

use App\Media\Media;
use App\Media\MediaUrls;
use App\Media\UploadValidator;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Properties\Buildings\Properties;
use Modules\Properties\Buildings\PropertyPhotos;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\Properties\Units\UnitDirectoryService;
use Modules\Properties\Units\Units;

/**
 * A building's page, /o/{organization}/buildings/{slug}: its details, owner,
 * amenities and photos, with archive or restore. Members with
 * `properties.view` open it (an archived building too, read-only);
 * `properties.manage` adds photos, picks the cover and removes photos;
 * `properties.portfolio` archives and restores (PropertyPolicy, checked
 * again by Buildings\Properties and Buildings\PropertyPhotos).
 *
 * Units (P02-02): occupancy counts read from the units' statuses, and a
 * status board of every unit, filtered by status (kept in the URL), with
 * links to add one or several units and to edit each; archived units are
 * listed on request with Restore. Unit actions check UnitPolicy (and
 * Units\Units again); a unit is looked up among this building's units only.
 *
 * Photos are checked by App\Media\UploadValidator as soon as they are
 * chosen and again when saved (MediaUploads), and shown through signed,
 * expiring links (MediaUrls) made after the page's own `view` check. The
 * building id is locked and the building loaded through the organization
 * scope on every request; a photo is found among this building's photos
 * only, so another building's or organization's file answers 404.
 */
#[Layout('layouts::app')]
#[Title('Building')]
final class PropertyShow extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $propertyId;

    /** @var array<int, TemporaryUploadedFile|mixed> photos chosen, not yet saved */
    public array $photos = [];

    #[Locked]
    public ?string $notice = null;

    /** Status board filter: a `units.status` value, or empty for every unit. */
    #[Url(as: 'status', except: '')]
    public string $boardStatus = '';

    /** Whether the archived units are listed. */
    #[Url(as: 'archived_units', except: false)]
    public bool $showArchivedUnits = false;

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
        $this->authorize('view', $property);
        $this->propertyId = $property->id;
    }

    /**
     * Chosen photos are checked at once; nothing is stored until
     * savePhotos(). A refused choice is cleared (Livewire adds new choices
     * to the list), so the next one starts afresh.
     */
    public function updatedPhotos(): void
    {
        $this->resetValidation();

        try {
            $this->validate($this->photoRules(app(UploadValidator::class)));
        } catch (ValidationException $refused) {
            $this->photos = [];

            throw $refused;
        }
    }

    public function clearPhotos(): void
    {
        $this->photos = [];
        $this->resetValidation();
    }

    public function savePhotos(PropertyPhotos $photos, UploadValidator $validator): void
    {
        $property = $this->property();
        $this->authorize('addPhotos', $property);
        $this->validate($this->photoRules($validator));

        $files = array_values(array_filter($this->photos, static fn (mixed $file): bool => $file instanceof UploadedFile));
        $added = $photos->add($this->user(), $property, $files);

        $this->photos = [];
        $this->notice = trans_choice(':count photo was added.|:count photos were added.', count($added), ['count' => count($added)]);
    }

    public function makeCover(int $mediaId, PropertyPhotos $photos): void
    {
        $property = $this->property();
        $this->authorize('addPhotos', $property);

        $photos->makeCover($this->user(), $property, $mediaId);
        $this->notice = __('The cover photo was changed.');
    }

    public function removePhoto(int $mediaId, PropertyPhotos $photos): void
    {
        $property = $this->property();
        $this->authorize('deletePhoto', $property);

        $photos->remove($this->user(), $property, $mediaId);
        $this->notice = __('The photo was removed.');
    }

    public function archiveUnit(int $unitId, Units $units): void
    {
        $unit = Unit::query()->where('property_id', $this->propertyId)->findOrFail($unitId);
        $this->authorize('delete', $unit);

        // Refused (ValidationException, key `archive`) while a lease holds the unit.
        $units->archive($this->user(), $unit);
        $this->notice = __(':label was archived. It no longer counts towards your bill; find it under archived units to restore it.', ['label' => $unit->unit_label]);
    }

    public function restoreUnit(int $unitId, Units $units): void
    {
        $unit = Unit::query()->onlyTrashed()->where('property_id', $this->propertyId)->findOrFail($unitId);
        $this->authorize('restore', $unit);

        $units->restore($this->user(), $unit);
        $this->notice = __(':label was restored.', ['label' => $unit->unit_label]);
    }

    public function archive(Properties $properties): void
    {
        $property = $this->property();
        $this->authorize('delete', $property);

        // Refused (ValidationException, key `archive`) while a lease holds one of its units.
        $properties->archive($this->user(), $property);
        $this->notice = __(':name was archived. Its units no longer count towards your bill; restore it to work on it again.', ['name' => $property->name]);
    }

    public function restore(Properties $properties): void
    {
        $property = $this->property();
        $this->authorize('restore', $property);

        $properties->restore($this->user(), $property);
        $this->notice = __(':name was restored.', ['name' => $property->name]);
    }

    public function render(OrganizationContext $context, MediaUrls $urls): View
    {
        $property = $this->property();
        $property->load('owner');
        $user = $this->user();

        $photos = $property->photos()->map(fn (Media $photo): array => [
            'id' => $photo->id,
            'name' => $photo->name,
            'thumb' => $urls->preview($photo, 'thumb'),
            'large' => $urls->preview($photo, 'large'),
            'cover' => $photo->id === $property->photo_media_id,
        ])->all();

        $filter = UnitStatus::tryFrom($this->boardStatus);
        $units = $property->units()->get()->sort(static fn (Unit $a, Unit $b): int => strnatcasecmp($a->unit_label, $b->unit_label))->values();
        $units->each(fn (Unit $unit) => $unit->setRelation('property', $property));
        $sampleUnit = $units->first();
        $archivedUnits = $this->showArchivedUnits
            ? Unit::query()->onlyTrashed()->where('property_id', $property->id)->get()->sort(static fn (Unit $a, Unit $b): int => strnatcasecmp($a->unit_label, $b->unit_label))->values()
            : collect();
        $archivedUnits->each(fn (Unit $unit) => $unit->setRelation('property', $property));
        $sampleArchived = $archivedUnits->first();

        return view('properties::livewire.property-show', [
            'organization' => $context->get(),
            // Occupancy counts come from the units' statuses (ARCHITECTURE.md § 6.2).
            'unitCounts' => UnitDirectoryService::countsOf($property->units()->getQuery()),
            'statuses' => UnitStatus::cases(),
            'boardFilter' => $filter,
            'boardUnits' => $filter === null ? $units : $units->filter(fn (Unit $unit): bool => $unit->status === $filter)->values(),
            'archivedUnits' => $archivedUnits,
            'archivedUnitCount' => $this->showArchivedUnits ? $archivedUnits->count() : Unit::query()->onlyTrashed()->where('property_id', $property->id)->count(),
            'canAddUnits' => $user->can('create', [Unit::class, $property]),
            'canEditUnits' => $sampleUnit instanceof Unit && $user->can('update', $sampleUnit),
            'canArchiveUnits' => $sampleUnit instanceof Unit && $user->can('delete', $sampleUnit),
            'canRestoreUnits' => $sampleArchived instanceof Unit && $user->can('restore', $sampleArchived),
            'property' => $property,
            'owner' => $property->owner,
            'canSeeOwner' => $property->owner instanceof OwnerProfile && $user->can('view', $property->owner),
            'photoList' => $photos,
            'canEdit' => $user->can('update', $property),
            'canAddPhotos' => $user->can('addPhotos', $property) && count($photos) < PropertyPhotos::MAX_PHOTOS,
            'canRemovePhotos' => $user->can('deletePhoto', $property),
            'canArchive' => $user->can('delete', $property),
            'canRestore' => $user->can('restore', $property),
            'maxPhotos' => PropertyPhotos::MAX_PHOTOS,
            'flash' => session('building-status'),
        ]);
    }

    /** @return array<string, mixed> */
    private function photoRules(UploadValidator $validator): array
    {
        return [
            'photos' => ['array', 'min:1', 'max:'.PropertyPhotos::MAX_PER_UPLOAD],
            'photos.*' => [$validator->rule(Property::PHOTOS)],
        ];
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
