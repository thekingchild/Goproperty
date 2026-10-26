<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backup\BackupRun;
use App\Backup\BackupRunner;
use Illuminate\Console\Command;

/**
 * Runs a backup now (the console's "Run backup now" comes in P06-10).
 * The nightly runs are scheduled in routes/console.php.
 */
final class BackupRunCommand extends Command
{
    protected $signature = 'goproperty:backup-run {type : db or files}';

    protected $description = 'Back up the database or the uploaded files to every backup destination';

    public function handle(BackupRunner $runner): int
    {
        $type = (string) $this->argument('type');

        if (! in_array($type, BackupRunner::TYPES, true)) {
            $this->components->error('The type is db or files.');

            return self::INVALID;
        }

        $runs = $runner->run($type, 'manual');

        $this->table(['Destination', 'Result', 'File', 'Size'], $runs->map(fn (BackupRun $run): array => [
            $run->destination->name ?? '—',
            $run->status,
            $run->filename ?? ($run->notes ?? ''),
            $run->size_bytes !== null ? number_format($run->size_bytes / 1024, 1).' KB' : '',
        ])->all());

        return $runs->every(fn (BackupRun $run): bool => $run->status === 'success') ? self::SUCCESS : self::FAILURE;
    }
}
