<?php

declare(strict_types=1);

namespace App\Backup;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Str;
use Throwable;

/**
 * Proves a destination works: write a small file, read it back, delete it.
 */
final readonly class DestinationCheck
{
    public function __construct(
        private Config $config,
        private FilesystemManager $filesystems,
    ) {}

    /** @return string|null the problem, or null when it works */
    public function problem(BackupDestination $destination): ?string
    {
        try {
            $this->config->set('filesystems.disks.'.$destination->diskName(), $destination->diskConfig());
            $this->filesystems->forgetDisk($destination->diskName());
            $disk = $this->filesystems->disk($destination->diskName());

            $path = 'goproperty-check/'.Str::random(16).'.txt';
            $content = 'GoProperty backup destination check '.now()->toIso8601String();
            $disk->put($path, $content);
            $read = $disk->get($path);
            $disk->delete($path);

            return $read === $content ? null : 'The test file came back different.';
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }
    }
}
