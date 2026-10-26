<?php

declare(strict_types=1);

namespace App\Backup;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Where backups go (PLATFORM_CONSOLE.md § 4): an S3-compatible bucket or a
 * Google Drive Shared Drive folder. Credentials are stored encrypted and
 * never shown again. "local" (a folder on this server) exists only outside
 * production, for drills: a backup on the same server is not a backup.
 *
 * @property int $id
 * @property string $name
 * @property string $driver s3|google_drive|local
 * @property array<string, mixed> $config
 * @property string $applies_to db|files|both
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'driver', 'config', 'applies_to', 'active'])]
#[Hidden(['config'])]
class BackupDestination extends Model
{
    public const array DRIVERS = ['s3', 'google_drive', 'local'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'applies_to' => 'both',
        'active' => true,
    ];

    /** The filesystem disk this destination becomes during a run. */
    public function diskName(): string
    {
        return 'backup-destination-'.$this->id;
    }

    /** @return array<string, mixed> */
    public function diskConfig(): array
    {
        $config = $this->config;

        return match ($this->driver) {
            's3' => [
                'driver' => 's3',
                'key' => $config['key'] ?? null,
                'secret' => $config['secret'] ?? null,
                'region' => $config['region'] ?? 'auto',
                'bucket' => $config['bucket'] ?? null,
                'endpoint' => $config['endpoint'] ?? null,
                'use_path_style_endpoint' => (bool) ($config['use_path_style_endpoint'] ?? false),
                'root' => $config['root'] ?? 'goproperty-backups',
                'throw' => true,
                'report' => false,
            ],
            'google_drive' => [
                'driver' => 'google_drive',
                'service_account' => $config['service_account'] ?? null,
                'shared_drive_id' => $config['shared_drive_id'] ?? null,
                'folder_id' => $config['folder_id'] ?? null,
                'throw' => true,
            ],
            'local' => app()->isProduction()
                ? throw new InvalidArgumentException('A local folder is not a backup destination in production.')
                : ['driver' => 'local', 'root' => (string) ($config['root'] ?? storage_path('app/backup-drill')), 'throw' => true],
            default => throw new InvalidArgumentException("Unknown backup destination driver {$this->driver}."),
        };
    }

    public function takes(string $type): bool
    {
        return $this->active && in_array($this->applies_to, [$type, 'both'], true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'active' => 'boolean',
        ];
    }
}
