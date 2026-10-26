<?php

declare(strict_types=1);

namespace App\Installer\Support;

use RuntimeException;

/**
 * Writes a file so readers see the old contents or the new, never half of
 * either: temp file in the same folder → fsync → rename (INSTALLER.md § 4).
 */
final class AtomicFile
{
    public static function write(string $path, string $contents, int $mode = 0600): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create the folder for {$path}.");
        }

        $temporary = $directory.'/.'.basename($path).'.tmp-'.bin2hex(random_bytes(6));
        $handle = @fopen($temporary, 'xb');

        if ($handle === false) {
            throw new RuntimeException("Cannot write in {$directory}.");
        }

        try {
            @chmod($temporary, $mode);

            if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
                throw new RuntimeException("Cannot write {$path}.");
            }

            // Some hosts' filesystems refuse fsync; the rename still applies.
            @fsync($handle);
        } catch (RuntimeException $exception) {
            fclose($handle);
            @unlink($temporary);

            throw $exception;
        }

        fclose($handle);

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Cannot replace {$path}.");
        }
    }
}
