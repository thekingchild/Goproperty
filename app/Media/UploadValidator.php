<?php

declare(strict_types=1);

namespace App\Media;

use Closure;
use finfo;
use Illuminate\Http\UploadedFile;
use ZipArchive;

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
 * Spreadsheets for imports need more than finfo, which reports an .xlsx as
 * a zip and a .csv as plain text: a file only counts as .xlsx when it opens
 * as a zip holding an Excel workbook (the OOXML content-types part naming a
 * spreadsheet workbook, `xl/workbook.xml`, no macros, a bounded unpacked
 * size), and only as .csv when it is text with no NUL bytes and no markup
 * or script tags. Anything else keeps its sniffed type and is refused.
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

        $mime = $this->spreadsheetType($mime, $path) ?? $mime;

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

    /**
     * The spreadsheet type a zip or text file really is, after the content
     * checks; null when the sniffed type is neither. A file claiming to be
     * a spreadsheet that fails them is downgraded to a type no collection
     * accepts.
     */
    private function spreadsheetType(string $mime, string $path): ?string
    {
        if (in_array($mime, [CollectionRules::XLSX, 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
            if ($this->isExcelWorkbook($path)) {
                return CollectionRules::XLSX;
            }

            return $mime === CollectionRules::XLSX ? 'application/zip' : null;
        }

        if (in_array($mime, ['text/plain', CollectionRules::CSV, 'application/csv', 'text/x-csv'], true)) {
            return $this->isPlainText($path) ? CollectionRules::CSV : 'text/plain';
        }

        return null;
    }

    /** A zip holding an Excel workbook without macros, small enough to read once unpacked. */
    private function isExcelWorkbook(string $path): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            if ($zip->numFiles < 3 || $zip->numFiles > 5000) {
                return false;
            }

            $names = [];
            $unpacked = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false) {
                    return false;
                }

                $names[$stat['name']] = true;
                $unpacked += $stat['size'];

                // A zip bomb unpacks to far more than any real 20 MB workbook.
                if ($unpacked > 256 * 1024 * 1024) {
                    return false;
                }
            }

            if (! isset($names['[Content_Types].xml'], $names['xl/workbook.xml']) || isset($names['xl/vbaProject.bin'])) {
                return false;
            }

            $types = $zip->getFromName('[Content_Types].xml', 1024 * 1024);

            return is_string($types)
                && str_contains($types, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml')
                && ! str_contains(strtolower($types), 'macroenabled');
        } finally {
            $zip->close();
        }
    }

    /** Text with no NUL bytes and nothing a browser or PHP would run (`<?`, `<script`, `<html`, …). */
    private function isPlainText(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $tail = '';

            while (! feof($handle)) {
                $chunk = fread($handle, 65536);

                if ($chunk === false) {
                    return false;
                }

                $window = $tail.$chunk;

                if (str_contains($chunk, "\0") || preg_match('/<\?|<\s*(script|html|body|iframe|svg|object|embed)\b|<!doctype/i', $window) === 1) {
                    return false;
                }

                $tail = substr($chunk, -16);
            }

            return true;
        } finally {
            fclose($handle);
        }
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
