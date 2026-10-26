<?php

declare(strict_types=1);

namespace App\Release;

use ZipArchive;

/**
 * Verifies a release zip as CI builds it (INSTALLER.md § 2):
 *
 *   README.txt
 *   preferred/goproperty/          document root → goproperty/public
 *   split/goproperty-app/          the application, outside the web root
 *   split/public_html/             public files only
 *
 * The zip is read in place. Unsafe entry names are refused before anything
 * would be unpacked, then each layout is checked with PackageVerifier.
 */
final readonly class ReleaseZip
{
    /** @var array<string, array<string, string>> layout => zip folder => path prefix */
    public const array LAYOUTS = [
        'preferred' => ['preferred/goproperty/' => ''],
        'split' => ['split/goproperty-app/' => '', 'split/public_html/' => 'public/'],
    ];

    public const string FILE_PATTERN = '/^goproperty-(.+)\.zip$/';

    public function __construct(private PackageVerifier $verifier) {}

    public function verify(string $path): VerificationReport
    {
        $name = basename($path);

        if (! is_file($path)) {
            return new VerificationReport(null, 0, ["{$path} does not exist."]);
        }

        $problems = [];
        $notes = [];

        $expected = preg_match(self::FILE_PATTERN, $name, $match) === 1
            && preg_match(PackageVerifier::VERSION_PATTERN, $match[1]) === 1 ? $match[1] : null;

        if ($expected === null) {
            $problems[] = "{$name}: the file name must be goproperty-<version>.zip, as CI names it.";
        }

        $this->checkSidecar($path, $problems, $notes);

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            $problems[] = "{$name} is not a readable zip file.";

            return new VerificationReport($expected, 0, $problems, $notes);
        }

        try {
            $this->checkEntries($zip, $problems);

            $files = 0;
            $versions = [];
            $trees = [];

            foreach (self::LAYOUTS as $layout => $mounts) {
                $tree = new ZipTree($zip, $mounts, "{$name} ({$layout})");

                if ($tree->entries() === []) {
                    $problems[] = "The {$layout} layout is missing.";

                    continue;
                }

                $report = $this->verifier->verify($tree, VerifyMode::Package);
                $files += $report->files;
                $versions[$layout] = $report->version;
                $trees[$layout] = $tree;

                foreach ($report->problems as $problem) {
                    $problems[] = "{$layout}: {$problem}";
                }
            }

            $this->checkLayoutShape($trees, $problems);
        } finally {
            $zip->close();
        }

        foreach ($versions as $layout => $version) {
            if ($version !== null && $expected !== null && $version !== $expected) {
                $problems[] = "{$layout}: VERSION says {$version} but the file name says {$expected}.";
            }
        }

        return new VerificationReport($expected ?? (array_values(array_filter($versions))[0] ?? null), $files, $problems, $notes);
    }

    public static function isSafeName(string $name): bool
    {
        return PackageVerifier::isSafePath(str_ends_with($name, '/') ? substr($name, 0, -1) : $name);
    }

    public static function isSymlink(ZipArchive $zip, int $index): bool
    {
        $system = 0;
        $attributes = 0;

        return $zip->getExternalAttributesIndex($index, $system, $attributes)
            && $system === ZipArchive::OPSYS_UNIX
            && (($attributes >> 16) & 0170000) === 0120000;
    }

    /**
     * @param  list<string>  $problems
     */
    private function checkEntries(ZipArchive $zip, array &$problems): void
    {
        $seen = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->getNameIndex($index);

            if ($entry === false) {
                $problems[] = "Zip entry {$index} has no readable name.";

                continue;
            }

            if (isset($seen[$entry])) {
                $problems[] = 'The zip holds '.PackageVerifier::printable($entry).' twice.';
            }

            $seen[$entry] = true;

            if (! self::isSafeName($entry)) {
                $problems[] = 'Unsafe path in the zip: '.PackageVerifier::printable($entry);
            } elseif (! $this->isExpected($entry)) {
                $problems[] = "{$entry} is outside the preferred/ and split/ layouts.";
            }
        }
    }

    private function isExpected(string $entry): bool
    {
        if (in_array($entry, ['README.txt', 'preferred/', 'split/'], true)) {
            return true;
        }

        foreach (self::LAYOUTS as $mounts) {
            foreach (array_keys($mounts) as $folder) {
                if (str_starts_with($entry, $folder)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The split layout tells artisan where its public files are; the
     * preferred layout must not, or it would look in the wrong folder.
     *
     * @param  array<string, ZipTree>  $trees
     * @param  list<string>  $problems
     */
    private function checkLayoutShape(array $trees, array &$problems): void
    {
        if (isset($trees['split'])) {
            $split = $trees['split'];

            if (! array_key_exists('bootstrap/public-path.php', $split->entries())) {
                $problems[] = 'split: bootstrap/public-path.php is missing.';
            }

            if (array_key_exists('public/index.php', $split->entries())
                && ! str_contains($split->read('public/index.php'), 'usePublicPath(__DIR__)')) {
                $problems[] = 'split: public_html/index.php does not set the public path.';
            }
        }

        if (isset($trees['preferred']) && array_key_exists('bootstrap/public-path.php', $trees['preferred']->entries())) {
            $problems[] = 'preferred: bootstrap/public-path.php belongs to the split layout only.';
        }
    }

    /**
     * The `.sha256` file CI publishes next to the zip.
     *
     * @param  list<string>  $problems
     * @param  list<string>  $notes
     */
    private function checkSidecar(string $path, array &$problems, array &$notes): void
    {
        $name = basename($path);
        $sidecar = $path.'.sha256';

        if (! is_file($sidecar)) {
            $notes[] = "No {$name}.sha256 next to the zip: compare the checksum with the one on the release page.";

            return;
        }

        $expected = preg_match('/^([0-9a-fA-F]{64})\b/', trim((string) file_get_contents($sidecar)), $match) === 1
            ? strtolower($match[1])
            : null;

        if ($expected === null) {
            $problems[] = "{$name}.sha256 does not hold a SHA-256 checksum.";
        } elseif (! hash_equals($expected, (string) hash_file('sha256', $path))) {
            $problems[] = "{$name} does not match {$name}.sha256: the file is damaged or was changed.";
        }
    }
}
