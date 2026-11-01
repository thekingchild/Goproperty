<?php

declare(strict_types=1);

namespace App\Backup\Operations;

use App\Backup\BackupRun;
use Illuminate\Support\Facades\URL;

/**
 * Links to download a backup zip from the console (P06-10;
 * PLATFORM_CONSOLE.md § 4): signed and valid for 15 minutes. The route
 * also checks `backups.download` and audits every download
 * (BackupDownloadController), so a forwarded link is useless to anyone else.
 */
final class DownloadLinks
{
    public const string ROUTE = 'filament.console.backup-download';

    public const int MINUTES = 15;

    public function for(BackupRun $run): string
    {
        return URL::temporarySignedRoute(self::ROUTE, now()->addMinutes(self::MINUTES), ['run' => $run->id]);
    }
}
