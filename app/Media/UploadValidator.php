<?php

declare(strict_types=1);

namespace App\Media;

use Closure;
use finfo;
use Illuminate\Http\UploadedFile;

/**
 * Checks a file before the media library stores it (ARCHITECTURE.md § 10:
 * "All uploads validated (mime sniffing, size caps, no SVG)"; AGENT.md
 * § 5). Whatever the browser said is ignored: the size is the file's own,
 * the type is sniffed from its bytes with finfo, and the stored extension
 * comes from that type. So a PHP script renamed `photo.jpg` is refused as
 * PHP, and an SVG is refused whatever it is called.
 *
 * Per collection (config/media.php): the accepted types and a size cap.
 * Images must also decode, their decoded type must match the sniffed one,
 * and they must stay within `max_side` and `max_megapixels` so GD can
 * convert them within a shared host's memory.
 *
 * Use check() before storing (MediaUploads does), problem() to show a
 * message, rule() inside Laravel validation (Livewire rules(), Form Requests).
 */
final class UploadValidator
{
    /** @throws UploadRefused with a message for the person uploading */
    public function check(string $collection, UploadedFile|string $file): CheckedUpload
    {
        $rules = CollectionRules::for($collection);
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new UploadRefused(__('The file could not be read. Choose it again.'));
        }

        $size = (int) filesize($path);

        if ($size === 0) {
            throw new UploadRefused(__('The file is empty. Choose it again.'));
        }

        if ($size > $rules->maxBytes()) {
            throw new UploadRefused(__('The file is larger than :size. Upload a smaller one.', ['size' => $rules->maxSizeLabel()]));
        }

        $mime = $this->sniff($path);

        if ($this->isSvg($mime, $path)) {
            throw new UploadRefused(__('SVG files are not accepted. Upload a :types file.', ['types' => $rules->typesLabel()]));
        }

        if (! $rules->accepts($mime)) {
            throw new UploadRefused(__('Upload a :types file. This file is not one, whatever its name says.', ['types' => $rules->typesLabel()]));
        }

        [$width, $height] = str_starts_with($mime, 'image/') ? $this->imageSize($path, $mime) : [null, null];

        return new CheckedUpload(
            path: $path,
            mime: $mime,
            extension: CollectionRules::EXTENSIONS[$mime],
            size: $size,
            sha256: (string) hash_file('sha256', $path),
            width: $width,
            height: $height,
        );
    }

    /** What is wrong with the file, or null when the collection accepts it. */
    public function problem(string $collection, UploadedFile|string $file): ?string
    {
        try {
            $this->check($collection, $file);

            return null;
        } catch (UploadRefused $refused) {
            return $refused->getMessage();
        }
    }

    /**
     * For Laravel validation: `'photo' => ['required', 'file', $validator->rule('photos')]`.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    public function rule(string $collection): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($collection): void {
            if (! $value instanceof UploadedFile) {
                $fail(__('Choose a file to upload.'));

                return;
            }

            $problem = $this->problem($collection, $value);

            if ($problem !== null) {
                $fail($problem);
            }
        };
    }

    /** The type from the file's bytes. */
    public function sniff(string $path): string
    {
        return (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    }

    private function isSvg(string $mime, string $path): bool
    {
        if (in_array($mime, ['image/svg+xml', 'image/svg'], true)) {
            return true;
        }

        // An SVG without its XML header can sniff as text or HTML.
        if (str_starts_with($mime, 'text/') || str_ends_with($mime, '/xml')) {
            $head = (string) file_get_contents($path, false, null, 0, 4096);

            return stripos($head, '<svg') !== false;
        }

        return false;
    }

    /**
     * Width and height of an image that decodes as the sniffed type and is
     * small enough to convert.
     *
     * @return array{int, int}
     *
     * @throws UploadRefused
     */
    private function imageSize(string $path, string $mime): array
    {
        $info = @getimagesize($path);

        if ($info === false || image_type_to_mime_type($info[2]) !== $mime) {
            throw new UploadRefused(__('The image could not be read. Save it again as JPEG or PNG.'));
        }

        [$width, $height] = $info;
        $maxSide = $this->setting('max_side', 8000);
        $maxMegapixels = $this->setting('max_megapixels', 25);

        if ($width < 1 || $height < 1 || $width > $maxSide || $height > $maxSide || $width * $height > $maxMegapixels * 1_000_000) {
            throw new UploadRefused(__('The image is too large (:width × :height pixels). Upload one of at most :megapixels megapixels and :side pixels a side.', [
                'width' => $width,
                'height' => $height,
                'megapixels' => $maxMegapixels,
                'side' => $maxSide,
            ]));
        }

        return [$width, $height];
    }

    private function setting(string $key, int $default): int
    {
        $value = config("media.{$key}");

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
