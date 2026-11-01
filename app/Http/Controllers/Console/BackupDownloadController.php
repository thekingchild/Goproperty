<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Backup\BackupRun;
use App\Backup\Operations\BackupAudit;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission;
use Filament\Facades\Filament;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Filesystem\FilesystemManager;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Downloads a backup zip for staff (P06-10; PLATFORM_CONSOLE.md § 4): a
 * console route behind the staff guard and 2FA, reached only through a
 * signed link that expires in 15 minutes (DownloadLinks), for staff holding
 * `backups.download`. The zip is streamed from its destination as it is
 * stored, still AES-256 encrypted, and every download is audited.
 */
final class BackupDownloadController
{
    public function __invoke(int $run, FilesystemManager $filesystems, Config $config, BackupAudit $audit): StreamedResponse
    {
        $staff = Filament::auth()->user();
        abort_unless($staff instanceof PlatformStaff && $staff->holdsStaffPermission(StaffPermission::BackupsDownload), 403);

        $record = BackupRun::query()->with('destination')->find($run);
        abort_if($record === null || $record->status !== 'success' || $record->filename === null || $record->destination === null, 404);

        $destination = $record->destination;
        $config->set('filesystems.disks.'.$destination->diskName(), $destination->diskConfig());
        $filesystems->forgetDisk($destination->diskName());

        try {
            $disk = $filesystems->disk($destination->diskName());
            $stream = $disk->exists($record->filename) ? $disk->readStream($record->filename) : null;
        } catch (Throwable) {
            $stream = null;
        }

        abort_if($stream === null, 404, 'This backup is no longer at its destination: retention may have removed it.');

        $audit->record('backup.downloaded', $record, $staff, "Downloaded {$record->filename} from {$destination->name}", [
            'type' => $record->type,
            'filename' => $record->filename,
            'destination' => $destination->name,
            'size_bytes' => $record->size_bytes,
        ]);

        $name = str_replace(['"', '\\', '/'], '-', $record->type.'-'.basename($record->filename));

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 'goproperty-'.$name, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
