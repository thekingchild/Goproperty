<?php

declare(strict_types=1);

use App\Media\Media;
use App\Media\MediaUploads;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;

/*
 * Helpers for tests/Feature/Media (P02-05): real image files made with GD
 * (the validator decodes them, so fakes without pixels will not do), and
 * uploads through MediaUploads inside an organization. The organization
 * itself owns the files in these tests (it is a HasMedia model).
 */

/** A JPEG, PNG or WebP of this size with some colour in it, as an upload. */
function mediaImage(string $name, int $width = 640, int $height = 480, string $type = 'jpeg'): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);

    for ($i = 0; $i < 24; $i++) {
        $colour = (int) imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
        imagefilledrectangle($image, random_int(0, $width - 1), random_int(0, $height - 1), random_int(0, $width - 1), random_int(0, $height - 1), $colour);
    }

    $base = (string) tempnam(sys_get_temp_dir(), 'gp-media-test-');
    @unlink($base);
    $path = $base.'.'.($type === 'jpeg' ? 'jpg' : $type);

    match ($type) {
        'jpeg' => imagejpeg($image, $path, 85),
        'png' => imagepng($image, $path),
        'webp' => imagewebp($image, $path, 80),
        default => throw new InvalidArgumentException($type),
    };

    return new UploadedFile($path, $name, null, null, true);
}

/** A small, real PDF, as an upload. */
function mediaPdf(string $name = 'proof.pdf'): UploadedFile
{
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
        ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    return UploadedFile::fake()->createWithContent($name, $pdf);
}

/** Adds a file to the organization's collection through MediaUploads, inside the organization. */
function uploadMedia(Organization $organization, UploadedFile|string $file, string $collection = 'photos'): Media
{
    return app(OrganizationContext::class)->run($organization, fn () => app(MediaUploads::class)->add($organization, $collection, $file));
}

/** The media rows the organization can see. */
function mediaCount(Organization $organization): int
{
    return app(OrganizationContext::class)->run($organization, fn () => Media::query()->count());
}
