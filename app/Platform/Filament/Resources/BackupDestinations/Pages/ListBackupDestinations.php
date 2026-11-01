<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\BackupDestinations\Pages;

use App\Platform\Filament\Resources\BackupDestinations\BackupDestinationResource;
use Filament\Resources\Pages\ListRecords;

final class ListBackupDestinations extends ListRecords
{
    protected static string $resource = BackupDestinationResource::class;

    public function getSubheading(): string
    {
        return 'Every backup goes to each active destination that takes it. Use at least one S3-compatible bucket at a different provider from the live media; Google Drive suits database backups.';
    }
}
