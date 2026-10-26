<?php

declare(strict_types=1);

namespace App\Release;

/**
 * The files of one release layout, addressed by their path from the
 * application root. Public files always appear under `public/`, even in the
 * split layout where they live in a sibling `public_html/` folder, so the
 * same SHA256SUMS rules apply to every layout (INSTALLER.md § 2).
 */
interface PackageTree
{
    /**
     * Every file and symlink (not directories), keyed by path. The value is
     * true for a symlink.
     *
     * @return array<string, bool>
     */
    public function entries(): array;

    public function read(string $path): string;

    public function describe(): string;
}
