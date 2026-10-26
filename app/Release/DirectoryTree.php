<?php

declare(strict_types=1);

namespace App\Release;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * An unpacked layout or a running installation. storage/ and bootstrap/cache/
 * are skipped: they hold logs, caches and install markers that change at run
 * time and are not part of the package.
 */
final class DirectoryTree implements PackageTree
{
    /** Paths written at run time, never listed in SHA256SUMS. */
    public const array RUNTIME_PATHS = ['storage', 'bootstrap/cache'];

    private readonly string $root;

    private readonly string $publicRoot;

    /** @var array<string, bool>|null */
    private ?array $entries = null;

    public function __construct(string $root, ?string $publicRoot = null)
    {
        $this->root = rtrim($root, '/');
        $this->publicRoot = rtrim($publicRoot ?? $this->root.'/public', '/');
    }

    /**
     * An unpacked folder: `preferred/goproperty` keeps its own `public/`;
     * `split/goproperty-app` uses the sibling `public_html/`.
     */
    public static function forLayout(string $root, ?string $publicRoot = null): self
    {
        $root = rtrim($root, '/');

        if ($publicRoot === null && ! is_dir($root.'/public') && is_dir(dirname($root).'/public_html')) {
            $publicRoot = dirname($root).'/public_html';
        }

        return new self($root, $publicRoot);
    }

    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];
        $publicInside = $this->publicRoot === $this->root.'/public';

        // With a separate public folder, a stray public/ beside the app is not
        // served, so it is not part of the layout.
        $skip = $publicInside ? self::RUNTIME_PATHS : [...self::RUNTIME_PATHS, 'public'];

        foreach ($this->walk($this->root, $skip) as $path => $isLink) {
            $entries[$path] = $isLink;
        }

        if (! $publicInside) {
            foreach ($this->walk($this->publicRoot, []) as $path => $isLink) {
                $entries['public/'.$path] = $isLink;
            }
        }

        ksort($entries, SORT_STRING);

        return $this->entries = $entries;
    }

    public function read(string $path): string
    {
        $contents = @file_get_contents($this->physical($path));

        if ($contents === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        return $contents;
    }

    public function describe(): string
    {
        return $this->publicRoot === $this->root.'/public'
            ? $this->root
            : "{$this->root} (public files in {$this->publicRoot})";
    }

    private function physical(string $path): string
    {
        if (str_starts_with($path, 'public/')) {
            return $this->publicRoot.'/'.substr($path, 7);
        }

        return $this->root.'/'.$path;
    }

    /**
     * @param  list<string>  $skip
     * @return iterable<string, bool>
     */
    private function walk(string $root, array $skip): iterable
    {
        if (! is_dir($root)) {
            return;
        }

        $offset = strlen($root) + 1;

        $directory = new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
        );

        // Symlinked folders are reported as links and never entered.
        $filtered = new RecursiveCallbackFilterIterator(
            $directory,
            static fn (SplFileInfo $file): bool => ! in_array(substr($file->getPathname(), $offset), $skip, true),
        );

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator($filtered) as $file) {
            yield substr($file->getPathname(), $offset) => $file->isLink();
        }
    }
}
