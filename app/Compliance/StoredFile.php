<?php

declare(strict_types=1);

namespace App\Compliance;

/** Where ComplianceFiles put an upload, and what it found in it. */
final readonly class StoredFile
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $originalName,
        public string $mime,
        public int $sizeBytes,
        public string $sha256,
    ) {}
}
