<?php

declare(strict_types=1);

namespace Modules\Properties\Buildings;

use App\Media\Media;
use App\Media\MediaUploads;
use App\Media\UploadRefused;
use App\Media\UploadValidator;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Properties\Models\Property;

/**
 * A building's photos (ARCHITECTURE.md § 9): files enter only through
 * App\Media\MediaUploads into the media collection `photos` (checked by
 * UploadValidator: type sniffed from the bytes, size cap, never SVG), and
 * the queued conversions make the `thumb` and `large` WebP versions. The
 * first photo becomes the cover (`properties.photo_media_id`); the cover
 * can be changed, and removing it moves the cover to the next photo.
 *
 * Every change checks PropertyPolicy (`addPhotos`, `deletePhoto`; so a
 * suspended organization changes nothing and impersonating staff remove
 * nothing) and is audited under log `properties`. A photo is looked up
 * among this building's own photos, so another building's or another
 * organization's file answers 404.
 */
final readonly class PropertyPhotos
{
    /** Photos a building may hold, and how many one upload may add. */
    public const int MAX_PHOTOS = 20;

    public const int MAX_PER_UPLOAD = 10;

    public function __construct(
        private MediaUploads $uploads,
        private UploadValidator $validator,
    ) {}

    /**
     * Adds the files, all or none: every file is checked before the first
     * is stored.
     *
     * @param  list<UploadedFile>  $files
     * @return list<Media>
     *
     * @throws ValidationException naming `photos.N` for a refused file
     */
    public function add(User $by, Property $property, array $files): array
    {
        Gate::forUser($by)->authorize('addPhotos', $property);

        if ($files === []) {
            throw ValidationException::withMessages(['photos' => __('Choose at least one photo.')]);
        }

        if (count($files) > self::MAX_PER_UPLOAD) {
            throw ValidationException::withMessages(['photos' => __('Add at most :max photos at a time.', ['max' => self::MAX_PER_UPLOAD])]);
        }

        $room = self::MAX_PHOTOS - $property->photos()->count();

        if (count($files) > $room) {
            throw ValidationException::withMessages(['photos' => $room > 0
                ? __('A building keeps at most :max photos; there is room for :room more.', ['max' => self::MAX_PHOTOS, 'room' => $room])
                : __('A building keeps at most :max photos. Remove one first.', ['max' => self::MAX_PHOTOS])]);
        }

        foreach ($files as $index => $file) {
            try {
                $this->validator->check(Property::PHOTOS, $file);
            } catch (UploadRefused $refused) {
                throw ValidationException::withMessages(["photos.{$index}" => $refused->getMessage()]);
            }
        }

        $added = [];

        foreach ($files as $file) {
            $media = $this->uploads->add($property, Property::PHOTOS, $file);
            $added[] = $media;

            activity(Properties::LOG)
                ->event('property.photo_added')
                ->performedOn($property)
                ->causedBy($by)
                ->withProperties(['building' => $property->name, 'media_uuid' => $media->uuid, 'sha256' => $media->checksum()])
                ->log('Building photo added');
        }

        if ($property->photo_media_id === null) {
            $this->setCover($property, $added[0]->id);
        }

        return $added;
    }

    /** @throws ModelNotFoundException when it is not one of the building's photos */
    public function makeCover(User $by, Property $property, int $mediaId): void
    {
        Gate::forUser($by)->authorize('addPhotos', $property);

        $photo = $this->photo($property, $mediaId);

        if ($property->photo_media_id !== $photo->id) {
            $this->setCover($property, $photo->id);
            $this->audit('property.cover_changed', $property, $by, 'Building cover photo changed', $photo);
        }
    }

    /** @throws ModelNotFoundException when it is not one of the building's photos */
    public function remove(User $by, Property $property, int $mediaId): void
    {
        Gate::forUser($by)->authorize('deletePhoto', $property);

        $photo = $this->photo($property, $mediaId);

        DB::transaction(function () use ($property, $photo): void {
            if ($property->photo_media_id === $photo->id) {
                $next = $property->photos()->first(fn (Media $media): bool => $media->id !== $photo->id);
                $this->setCover($property, $next?->id);
            }
        });

        $this->audit('property.photo_removed', $property, $by, 'Building photo removed', $photo);
        // The media library removes the row and the files (with conversions).
        $photo->delete();
    }

    private function photo(Property $property, int $mediaId): Media
    {
        return Media::query()
            ->where('model_type', $property->getMorphClass())
            ->where('model_id', $property->getKey())
            ->where('collection_name', Property::PHOTOS)
            ->findOrFail($mediaId);
    }

    private function setCover(Property $property, ?int $mediaId): void
    {
        $property->photo_media_id = $mediaId;
        $property->save();
    }

    private function audit(string $event, Property $property, User $by, string $description, Media $photo): void
    {
        activity(Properties::LOG)
            ->event($event)
            ->performedOn($property)
            ->causedBy($by)
            ->withProperties(['building' => $property->name, 'media_uuid' => $photo->uuid])
            ->log($description);
    }
}
