<?php

declare(strict_types=1);

namespace App\Branding;

use App\Media\Media;
use App\Media\MediaUploads;
use App\Media\UploadRefused;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use finfo;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * An organization's logo, signature and stamp images (BRANDING_DOCUMENTS.md
 * § 1, ARCHITECTURE.md § 9–10).
 *
 * Checked on the server whatever the browser said: at most 1 MB, the type
 * sniffed from the bytes (finfo) and only PNG, JPEG or WebP (never SVG,
 * which can carry script), a size GD can safely decode, and a minimum
 * width. Then decoded and re-encoded as PNG (which drops anything hidden
 * in the file and any EXIF data), trimmed of a plain border and scaled
 * down, and stored in the media library (P02-05): collection `branding`
 * of the organization, on the private media disk, its role kept as a
 * custom property. `organizations.branding` holds the media ids
 * (`logo_media_id`, `signature_media_id`, `stamp_media_id`; the logo's is
 * also `organizations.logo_media_id`). Served only through the workspace
 * route (BrandingFileController); PDFs embed them as data URIs.
 *
 * Old images are kept: issued documents keep the branding they were issued
 * with (§ 1). Images stored before P02-05 on the branding disk were moved
 * into the media library by the 2026_11_02_050002 migration.
 */
final readonly class BrandingFiles
{
    /** Role => smallest width accepted, largest width and height stored. */
    public const array ROLES = [
        'logo' => ['min_width' => 300, 'max_width' => 800, 'max_height' => 400],
        'signature' => ['min_width' => 150, 'max_width' => 600, 'max_height' => 300],
        'stamp' => ['min_width' => 150, 'max_width' => 500, 'max_height' => 500],
    ];

    public const int MAX_KB = 1024;

    /** Decoding bigger images could exhaust a shared host's memory. */
    public const int MAX_SIDE = 5000;

    public const array MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    /** The media collection (config/media.php) the stored PNGs go to. */
    public const string COLLECTION = 'branding';

    public function __construct(
        private MediaUploads $uploads,
        private OrganizationContext $context,
    ) {}

    /**
     * What is wrong with an upload, in words for the person uploading, or
     * null when it can be stored.
     */
    public function problem(string $role, UploadedFile $file): ?string
    {
        $spec = self::ROLES[$role] ?? throw new RuntimeException("Unknown branding image {$role}.");
        $path = $file->getRealPath();
        $size = $path !== false ? @filesize($path) : false;

        if ($size === false || $size === 0) {
            return 'The file is empty or could not be read. Choose it again.';
        }

        if ($size > self::MAX_KB * 1024) {
            return 'The image is larger than 1 MB. Upload a smaller one.';
        }

        $bytes = (string) file_get_contents($path);

        if (! in_array($this->sniff($bytes), self::MIMES, true)) {
            return 'Upload a PNG, JPEG or WebP image. SVG and other files are not accepted.';
        }

        $dimensions = @getimagesizefromstring($bytes);

        if ($dimensions === false) {
            return 'The image could not be read. Save it again as PNG or JPEG.';
        }

        [$width, $height] = $dimensions;

        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE) {
            return 'The image is larger than '.self::MAX_SIDE.' pixels on a side.';
        }

        if ($width < $spec['min_width']) {
            return 'The image must be at least '.$spec['min_width'].' pixels wide.';
        }

        return null;
    }

    /**
     * Stores a checked upload in the media library (collection `branding`,
     * owned by the organization) and returns the media id for
     * `organizations.branding` (and `organizations.logo_media_id`).
     *
     * @throws RuntimeException with the problem when the file cannot be stored
     */
    public function store(Organization $organization, string $role, UploadedFile $file): int
    {
        $problem = $this->problem($role, $file);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        $image = @imagecreatefromstring((string) file_get_contents((string) $file->getRealPath()));

        if (! $image instanceof GdImage) {
            throw new RuntimeException('The image could not be read. Save it again as PNG or JPEG.');
        }

        $png = $this->encode($this->fit($this->trim($image), self::ROLES[$role]['max_width'], self::ROLES[$role]['max_height']));
        $temporary = tempnam(sys_get_temp_dir(), 'gp-branding-');

        if ($temporary === false || file_put_contents($temporary, $png) === false) {
            throw new RuntimeException('The image could not be stored. Try again.');
        }

        try {
            return $this->context->run($organization, fn (): int => $this->uploads->add($organization, self::COLLECTION, $temporary, $role.'.png', ['role' => $role])->id);
        } catch (UploadRefused $refused) {
            throw new RuntimeException($refused->getMessage(), previous: $refused);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** The stored image of this role, or null when it is missing or not this organization's. */
    public function media(Organization $organization, string $role, ?int $mediaId): ?Media
    {
        if ($mediaId === null || ! isset(self::ROLES[$role])) {
            return null;
        }

        return $this->context->run($organization, fn (): ?Media => $this->ofRole(
            Media::query()->whereKey($mediaId)->where('collection_name', self::COLLECTION)->first(),
            $role,
        ));
    }

    /** The image of this role with this uuid, for the serving route. */
    public function locate(Organization $organization, string $role, string $uuid): ?Media
    {
        if (! isset(self::ROLES[$role]) || ! Str::isUuid($uuid)) {
            return null;
        }

        return $this->context->run($organization, fn (): ?Media => $this->ofRole(
            Media::query()->where('uuid', $uuid)->where('collection_name', self::COLLECTION)->first(),
            $role,
        ));
    }

    /** The stored PNG's bytes, or null when the file is missing. */
    public function read(Media $media): ?string
    {
        try {
            $bytes = Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
        } catch (Throwable) {
            return null;
        }

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /** data:image/png;base64,… for PDFs (dompdf and Chrome read these with no network). */
    public function dataUri(Organization $organization, string $role, ?int $mediaId): ?string
    {
        $media = $this->media($organization, $role, $mediaId);
        $bytes = $media !== null ? $this->read($media) : null;

        return $bytes !== null ? 'data:image/png;base64,'.base64_encode($bytes) : null;
    }

    /** The workspace URL that serves the image, for web pages and the live preview. */
    public function url(Organization $organization, string $role, ?int $mediaId): ?string
    {
        $media = $this->media($organization, $role, $mediaId);

        return $media !== null
            ? route('workspace.settings.branding.file', ['organization' => $organization->slug, 'role' => $role, 'file' => $media->uuid])
            : null;
    }

    public function sniff(string $bytes): string
    {
        return (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    }

    private function ofRole(?Media $media, string $role): ?Media
    {
        return $media !== null && $media->getCustomProperty('role') === $role ? $media : null;
    }

    /** Cuts away a plain border (white or transparent margins around a logo). */
    private function trim(GdImage $image): GdImage
    {
        imagesavealpha($image, true);
        $cropped = @imagecropauto($image, IMG_CROP_SIDES);

        return $cropped instanceof GdImage && imagesx($cropped) >= 16 && imagesy($cropped) >= 16 ? $cropped : $image;
    }

    private function fit(GdImage $image, int $maxWidth, int $maxHeight): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxWidth / max(1, $width), $maxHeight / max(1, $height));

        if ($scale >= 1 && imageistruecolor($image)) {
            return $image;
        }

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagefill($resized, 0, 0, (int) imagecolorallocatealpha($resized, 255, 255, 255, 127));
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $resized;
    }

    private function encode(GdImage $image): string
    {
        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }
}
