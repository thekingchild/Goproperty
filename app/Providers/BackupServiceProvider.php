<?php

declare(strict_types=1);

namespace App\Providers;

use App\Backup\GoogleDrive\DriveClient;
use App\Backup\GoogleDrive\GoogleDriveAdapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use RuntimeException;

/**
 * The "google_drive" filesystem driver for backup destinations
 * (PLATFORM_CONSOLE.md § 4).
 */
final class BackupServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('google_drive', function ($app, array $config): FilesystemAdapter {
            $account = $config['service_account'] ?? null;
            $account = is_string($account) ? json_decode($account, true) : $account;
            $drive = $config['shared_drive_id'] ?? null;

            if (! is_array($account) || ! is_string($drive) || $drive === '') {
                throw new RuntimeException('A Google Drive destination needs a service account key and a Shared Drive id.');
            }

            $folder = is_string($config['folder_id'] ?? null) && $config['folder_id'] !== '' ? $config['folder_id'] : $drive;
            $chunk = is_int($config['chunk_size'] ?? null) ? $config['chunk_size'] : DriveClient::CHUNK;
            $adapter = new GoogleDriveAdapter(new DriveClient($app->make(Http::class), $account, $drive, $chunk), $folder);

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
    }
}
