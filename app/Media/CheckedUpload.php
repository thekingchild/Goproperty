<?php

declare(strict_types=1);

namespace App\Media;

/** A file UploadValidator accepted, with what it found out from the bytes. */
final readonly class CheckedUpload
{
    public function __construct(
        public string $path,
        public string $mime,
        public string $extension,
        public int $size,
        public string $sha256,
        public ?int $width = null,
        public ?int $height = null,
    ) {}

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
