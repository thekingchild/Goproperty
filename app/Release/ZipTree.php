<?php

declare(strict_types=1);

namespace App\Release;

use RuntimeException;
use ZipArchive;

/**
 * One layout inside a release zip, read without unpacking it, so a zip with
 * unsafe paths can be refused before anything is written to disk.
 */
final class ZipTree implements PackageTree
{
    /** @var array<string, bool>|null */
    private ?array $entries = null;

    /** @var array<string, string> logical path => zip entry name */
    private array $names = [];

    /**
     * @param  array<string, string>  $mounts  zip folder (e.g. "split/public_html/") => path prefix ("public/")
     */
    public function __construct(
        private readonly ZipArchive $zip,
        private readonly array $mounts,
        private readonly string $label,
    ) {}

    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];

        for ($index = 0; $index < $this->zip->numFiles; $index++) {
            $name = $this->zip->getNameIndex($index);

            if ($name === false || str_ends_with($name, '/') || ! ReleaseZip::isSafeName($name)) {
                continue;
            }

            foreach ($this->mounts as $folder => $prefix) {
                if (str_starts_with($name, $folder)) {
                    $path = $prefix.substr($name, strlen($folder));
                    $entries[$path] = ReleaseZip::isSymlink($this->zip, $index);
                    $this->names[$path] = $name;
                    break;
                }
            }
        }

        ksort($entries, SORT_STRING);

        return $this->entries = $entries;
    }

    public function read(string $path): string
    {
        $this->entries();
        $contents = isset($this->names[$path]) ? $this->zip->getFromName($this->names[$path]) : false;

        if ($contents === false) {
            throw new RuntimeException("Cannot read {$path} from the zip.");
        }

        return $contents;
    }

    public function describe(): string
    {
        return $this->label;
    }
}
