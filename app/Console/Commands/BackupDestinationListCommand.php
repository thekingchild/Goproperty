<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backup\BackupDestination;
use App\Backup\BackupRun;
use Illuminate\Console\Command;

/** Lists backup destinations and their last successful backups. */
final class BackupDestinationListCommand extends Command
{
    protected $signature = 'goproperty:backup-destination:list';

    protected $description = 'List backup destinations and their last backups';

    public function handle(): int
    {
        $rows = BackupDestination::query()->orderBy('id')->get()->map(function (BackupDestination $destination): array {
            $last = fn (string $type): string => BackupRun::query()->where('backup_destination_id', $destination->id)->where('type', $type)
                ->where('status', 'success')->latest('finished_at')->value('finished_at') ?? '—';

            return [$destination->id, $destination->name, $destination->driver, $destination->applies_to, $destination->active ? 'on' : 'off', (string) $last('db'), (string) $last('files')];
        });

        $this->table(['#', 'Name', 'Driver', 'For', 'Active', 'Last database', 'Last files'], $rows->all());

        return self::SUCCESS;
    }
}
