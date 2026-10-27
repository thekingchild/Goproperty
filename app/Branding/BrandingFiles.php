<?php

declare(strict_types=1);

namespace App\Branding;

use App\Models\Organization;
use finfo;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * An organization's logo, signature and stamp images (BRANDING_DOCUMENTS.md
 * § 1, ARCHITECTURE.md § 9–10), until the media library (P02-05) takes
 * them over.
 *
 * Checked on the server whatever the browser said: at most 1 MB, the type
 * sniffed from the bytes (finfo) and only PNG, JPEG or WebP (never SVG,
 * which can carry script), a size GD can safely decode, and a minimum
 * width. Then decoded and re-encoded as PNG (which drops anything hidden
 * in the file and any EXIF data), trimmed of a plain border and scaled
 * down, and stored on a private disk (config/branding.php) under the
 * organization's folder, named by content hash. Served only through the
 * workspace route (BrandingFileController); PDFs embed them as data URIs.
 *
 * Old files are kept: issued documents keep the branding they were issued
 * with (§ 1), and P02-05 decides what to prune.
 */
final class BrandingFiles
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
     * Stores a checked upload and returns its path for `organizations.branding`.
     *
     * @throws RuntimeException with the problem when the file cannot be stored
     */
    public function store(Organization $organization, string $role, UploadedFile $file): string
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
        $path = $this->folder($organization).'/'.$role.'/'.substr(hash('sha256', $png), 0, 32).'.png';
        $this->disk()->put($path, $png);

        return $path;
    }

    /** The stored file's bytes, or null when it is missing or not this organization's. */
    public function read(Organization $organization, string $path): ?string
    {
        if (! $this->belongsTo($organization, $path)) {
            return null;
        }

        $bytes = $this->disk()->get($path);

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /** data:image/png;base64,… for PDFs (dompdf and Chrome read these with no network). */
    public function dataUri(Organization $organization, ?string $path): ?string
    {
        $bytes = $path !== null ? $this->read($organization, $path) : null;

        return $bytes !== null ? 'data:image/png;base64,'.base64_encode($bytes) : null;
    }

    /** The workspace URL that serves the file, for web pages and the live preview. */
    public function url(Organization $organization, string $role, ?string $path): ?string
    {
        if ($path === null || ! $this->belongsTo($organization, $path) || ! str_starts_with($path, $this->folder($organization).'/'.$role.'/')) {
            return null;
        }

        return route('workspace.settings.branding.file', ['organization' => $organization->slug, 'role' => $role, 'file' => pathinfo($path, PATHINFO_FILENAME)]);
    }

    /** The stored path for a role and hash, for the serving route. */
    public function locate(Organization $organization, string $role, string $hash): ?string
    {
        if (! isset(self::ROLES[$role]) || preg_match('/^[a-f0-9]{32}$/D', $hash) !== 1) {
            return null;
        }

        $path = $this->folder($organization).'/'.$role.'/'.$hash.'.png';

        return $this->disk()->exists($path) ? $path : null;
    }

    public function sniff(string $bytes): string
    {
        return (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    }

    public function disk(): Filesystem
    {
        $disk = config('branding.disk', 'local');

        return Storage::disk(is_string($disk) && $disk !== '' ? $disk : 'local');
    }

    private function folder(Organization $organization): string
    {
        $folder = config('branding.folder', 'organization-branding');

        return (is_string($folder) && $folder !== '' ? $folder : 'organization-branding').'/'.$organization->getKey();
    }

    private function belongsTo(Organization $organization, string $path): bool
    {
        return preg_match('#^'.preg_quote($this->folder($organization), '#').'/(logo|signature|stamp)/[a-f0-9]{32}\.png$#D', $path) === 1;
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
