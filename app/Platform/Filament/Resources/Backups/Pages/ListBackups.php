<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Backups\Pages;

use App\Backup\BackupRun;
use App\Backup\Operations\BackupOperation;
use App\Backup\Operations\OperationKind;
use App\Backup\Operations\OperationStatus;
use App\Platform\Filament\Resources\Backups\BackupResource;
use Filament\Resources\Pages\ListRecords;

final class ListBackups extends ListRecords
{
    protected static string $resource = BackupResource::class;

    /** Where things stand: the last good backups, the last verify and the last drill. */
    public function getSubheading(): string
    {
        $last = fn (string $type): string => BackupRun::query()->where('type', $type)->where('status', 'success')->latest('finished_at')->first()?->finished_at?->timezone('Africa/Lagos')->format('j M Y H:i') ?? 'never';
        $verified = BackupRun::query()->whereNotNull('verified_at')->latest('verified_at')->first()?->verified_at?->timezone('Africa/Lagos')->format('j M Y') ?? 'never';
        $drill = BackupOperation::query()->where('kind', OperationKind::Drill)->where('status', OperationStatus::Succeeded)->latest('drill_date')->first()?->drill_date?->format('j M Y') ?? 'never';

        return "Last database backup: {$last('db')}. Last files backup: {$last('files')}. Last verified: {$verified}. Last passed drill: {$drill}. Nightly at 01:30 (database) and 02:30 (files) server time (".config('app.timezone').'); verified on the 1st of each month.';
    }
}
