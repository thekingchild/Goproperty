<?php

declare(strict_types=1);

namespace App\Installer\Upgrades;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\File;

/**
 * One upgrade step at a time (INSTALLER.md § 10): an exclusive `flock` on a
 * file in storage/, taken without waiting. A file rather than a cache lock,
 * because the cache and its tables belong to the schema the step is
 * changing; the operating system frees the lock if the request dies.
 */
final readonly class UpgradeLock
{
    public function __construct(private Config $config) {}

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws UpgradeRefused while another request holds the lock
     */
    public function run(callable $callback): mixed
    {
        $path = $this->path();
        File::ensureDirectoryExists(dirname($path), 0700);
        $handle = fopen($path, 'c');

        if ($handle === false) {
            throw new UpgradeRefused('The upgrade lock file cannot be opened: make storage/app/private writable.');
        }

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new UpgradeRefused('Another staff member is running the upgrade right now. Wait for it to finish, then open this page again.');
            }

            try {
                return $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    public function path(): string
    {
        $path = $this->config->get('installer.paths.upgrade_lock');

        return is_string($path) && $path !== '' ? $path : storage_path('app/private/upgrade.lock');
    }
}
