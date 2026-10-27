<?php

declare(strict_types=1);

namespace App\Media;

use App\Models\Organization;
use App\Tenancy\CrossOrganizationWrite;
use App\Tenancy\MissingOrganizationContext;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use LogicException;
use Spatie\MediaLibrary\HasMedia;

/**
 * The one way files enter the media library (ARCHITECTURE.md § 9–10):
 * checks the file (UploadValidator: sniffed type, size cap, never SVG),
 * then stores it on the media disk under a random name with the extension
 * of its sniffed type, keeping the uploader's file name only as a label and
 * the sha256 of the bytes for integrity checks. Conversions, if the
 * collection has any, are queued.
 *
 * Runs inside an organization: the owner must belong to it (or be it), and
 * the media row is created in it. The uploaded file is moved, not copied.
 */
final readonly class MediaUploads
{
    public function __construct(
        private UploadValidator $validator,
        private OrganizationContext $context,
    ) {}

    /**
     * @param  Model&HasMedia  $owner  a saved record of the current organization, or the organization itself
     * @param  array<string, scalar|null>  $properties  extra custom properties to keep with the file
     *
     * @throws UploadRefused when the file is not accepted for the collection
     * @throws MissingOrganizationContext
     * @throws CrossOrganizationWrite when the owner is another organization's
     */
    public function add(HasMedia $owner, string $collection, UploadedFile|string $file, ?string $originalName = null, array $properties = []): Media
    {
        $organization = $this->context->get();
        $this->assertOwnedBy($owner, $organization);

        $checked = $this->validator->check($collection, $file);
        $label = $originalName ?? ($file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file));

        $media = $owner->addMedia($file)
            ->usingName(self::label($label))
            ->usingFileName(Str::lower(Str::random(40)).'.'.$checked->extension)
            ->withCustomProperties(array_merge($properties, array_filter([
                'sha256' => $checked->sha256,
                'width' => $checked->width,
                'height' => $checked->height,
            ], static fn (mixed $value): bool => $value !== null)))
            ->withAttributes(['mime_type' => $checked->mime])
            ->toMediaCollection($collection);

        if (! $media instanceof Media) {
            throw new LogicException('config/media-library.php must use '.Media::class.' as the media model.');
        }

        return $media;
    }

    /** The uploader's file name as a label: no folders, no extension, no control characters. */
    public static function label(string $name): string
    {
        $base = pathinfo(str_replace('\\', '/', $name), PATHINFO_FILENAME);
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F"<>]+/u', '', $base));

        return $clean !== '' ? Str::limit($clean, 120, '') : 'file';
    }

    private function assertOwnedBy(HasMedia $owner, Organization $organization): void
    {
        if (! $owner instanceof Model || ! $owner->exists) {
            throw new LogicException('Files are added to a saved record.');
        }

        $ownerOrganization = $owner instanceof Organization ? $owner->getKey() : $owner->getAttribute('organization_id');

        if (! is_numeric($ownerOrganization) || (int) $ownerOrganization !== $organization->id) {
            throw new CrossOrganizationWrite('A file cannot be added to another organization\'s record.');
        }
    }
}
