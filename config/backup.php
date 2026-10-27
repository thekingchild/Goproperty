<?php

declare(strict_types=1);

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;

/*
|--------------------------------------------------------------------------
| Backups (PLATFORM_CONSOLE.md § 4)
|--------------------------------------------------------------------------
|
| spatie/laravel-backup does the packaging, encryption, copying and
| retention. App\Backup\BackupRunner runs it in-process (no exec or
| proc_open, no signals), as two separate jobs, "db" and "files", each with
| its own backup name so retention never mixes them. Destinations are not
| listed here: they live in the backup_destinations table (encrypted
| credentials, set from the console or `goproperty:backup-destination`) and
| are turned into filesystem disks for each run.
|
*/

return [

    'backup' => [
        // Replaced per job: goproperty-db / goproperty-files.
        'name' => 'goproperty',

        'source' => [
            'files' => [
                // Uploaded media and documents; the code comes from the release zip.
                'include' => [
                    storage_path('app/brand'),
                    // Transaction Tier documents on the local `compliance`
                    // disk (already encrypted by the app). A `compliance-s3`
                    // bucket is mirrored outside the app, like other large
                    // media (PLATFORM_CONSOLE.md § 4).
                    storage_path('app/compliance'),
                    storage_path('app/private'),
                    storage_path('app/public'),
                ],
                'exclude' => [
                    storage_path('app/backup-temp'),
                    storage_path('app/private/livewire-tmp'),
                ],
                'follow_links' => false,
                'ignore_unreadable_directories' => true,
                'relative_path' => storage_path('app'),
            ],

            'databases' => [
                env('DB_CONNECTION', 'mariadb'),
            ],
        ],

        'database_dump_compressor' => null,
        'database_dump_file_timestamp_format' => null,
        'database_dump_filename_base' => 'database',
        'database_dump_file_extension' => 'sql',

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,
            'filename_prefix' => '',
            // Filled per run from backup_destinations.
            'disks' => [],
            // One unreachable destination must not stop the other.
            'continue_on_failure' => true,
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        // Set per run by App\Backup\ArchivePassword (AES-256 zip encryption).
        'password' => null,
        'encryption' => 'default',

        // Re-open the zip after writing it, before copying it anywhere.
        'verify_backup' => true,

        'tries' => 1,
        'retry_delay' => 0,
    ],

    // Failures are recorded in backup_runs and logged as critical; alerts
    // come with the operations dashboard (P08-03). No mail from the package.
    'notifications' => [
        'notifications' => [],
        'notifiable' => Notifiable::class,
        'mail' => ['to' => [], 'from' => ['address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'), 'name' => env('MAIL_FROM_NAME', 'GoProperty')]],
        'slack' => ['webhook_url' => '', 'channel' => null, 'username' => null, 'icon' => null],
        'discord' => ['webhook_url' => '', 'username' => '', 'avatar_url' => ''],
        'webhook' => ['url' => ''],
    ],

    'log_channel' => null,

    'monitor_backups' => [],

    // Retention (PLATFORM_CONSOLE.md § 4): 30 daily and 12 monthly.
    'cleanup' => [
        'strategy' => DefaultStrategy::class,
        'default_strategy' => [
            'keep_all_backups_for_days' => 1,
            'keep_daily_backups_for_days' => 30,
            'keep_weekly_backups_for_weeks' => 0,
            'keep_monthly_backups_for_months' => 12,
            'keep_yearly_backups_for_years' => 0,
            'delete_oldest_backups_when_using_more_megabytes_than' => null,
        ],
        'tries' => 1,
        'retry_delay' => 0,
    ],

    /*
    | How the database is dumped. "php" (default) needs no exec or proc_open,
    | so it works on shared hosting (ARCHITECTURE.md § 12); "mysqldump" uses
    | the binary through spatie/db-dumper where the host allows it.
    */
    'dumper' => env('BACKUP_DUMPER', 'php'),

    /*
    | A database the monthly verify may wipe and restore into
    | (`goproperty:backup-verify`). Shared hosts cannot create databases from
    | the app, so it is created in the control panel and named here.
    */
    'verify_database' => env('BACKUP_VERIFY_DB_DATABASE'),

    /*
    | The zip password. Empty: derived from APP_KEY (App\Backup\ArchivePassword).
    | At least 20 characters when set.
    */
    'archive_password' => env('BACKUP_ARCHIVE_PASSWORD'),

];
