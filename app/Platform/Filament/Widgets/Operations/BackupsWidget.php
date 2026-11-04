<?php

declare(strict_types=1);

namespace App\Platform\Filament\Widgets\Operations;

use App\Platform\Filament\Resources\Backups\BackupResource;
use App\Platform\Operations\Health\BackupHealth;
use App\Platform\Operations\Scheduler\ScheduledTasks;
use App\Platform\Staff\StaffPermission as P;
use Carbon\CarbonImmutable;

/** Backups (PLATFORM_CONSOLE.md § 2, § 4): last database and files backup, per destination, size, result, next scheduled run, last verify and drill. */
final class BackupsWidget extends OperationsWidget
{
    protected static ?int $sort = 40;

    protected string $view = 'console.operations.backups';

    public static function canView(): bool
    {
        return self::allowed(P::OperationsView, P::BackupsRun, P::BackupsDownload, P::BackupsVerify, P::BackupsRestoreStaging, P::BackupsRestoreProduction, P::BackupDestinations);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $health = app(BackupHealth::class);
        $next = [];

        foreach (app(ScheduledTasks::class)->all(CarbonImmutable::now()) as $task) {
            if (in_array($task->name, ['backup-db', 'backup-files'], true)) {
                $next[substr($task->name, 7)] = $task->nextRunAt;
            }
        }

        return [
            'last' => array_map(fn (string $type) => $health->lastSuccess($type), array_combine(array_keys(BackupHealth::TYPES), array_keys(BackupHealth::TYPES))),
            'next' => $next,
            'destinations' => $health->byDestination(),
            'verify' => $health->lastVerify(),
            'drill' => $health->lastDrill(),
            'backupsUrl' => BackupResource::canViewAny() ? BackupResource::getUrl() : null,
        ];
    }
}
