<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\BackupOperations\Pages;

use App\Platform\Filament\Resources\BackupOperations\BackupOperationResource;
use Filament\Resources\Pages\ListRecords;

final class ListBackupOperations extends ListRecords
{
    protected static string $resource = BackupOperationResource::class;

    public function getSubheading(): string
    {
        return 'Backups run now, verifies, restores and recorded drills. Requests start at the scheduler\'s next minute; a restore to production waits for a super-admin\'s approval in Staff → Approvals.';
    }
}
