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
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Properties\Buildings\Properties;
use Modules\Properties\Buildings\PropertyPhotos;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;

/**
 * A building's page, /o/{organization}/buildings/{slug}: its details, owner,
 * amenities and photos, with archive or restore. Members with
 * `properties.view` open it (an archived building too, read-only);
 * `properties.manage` adds photos, picks the cover and removes photos;
 * `properties.portfolio` archives and restores (PropertyPolicy, checked
 * again by Buildings\Properties and Buildings\PropertyPhotos). Units arrive
 * on this page with P02-02.
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

    public function archive(Properties $properties): void
    {
        $property = $this->property();
        $this->authorize('delete', $property);

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

        return view('properties::livewire.property-show', [
            'organization' => $context->get(),
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
