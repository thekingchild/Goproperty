<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backup\BackupDestination;
use App\Backup\Restorer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Restores a database backup into another database (docs/runbooks/
 * restore.md): from a zip on disk, or the newest one at a destination.
 * Never the site's own database; after checking the result, point
 * DB_DATABASE at the restored database.
 */
final class BackupRestoreCommand extends Command
{
    protected $signature = 'goproperty:backup-restore
        {source : Path to a backup zip, or "latest"}
        {--database= : The database to restore into (it is emptied first; never the site\'s own)}
        {--destination= : With "latest": the backup destination id (default: the first that takes database backups)}
        {--force : Do not ask before emptying the target database}';

    protected $description = 'Decrypt a database backup and load it into another database';

    public function handle(Restorer $restorer): int
    {
        $database = (string) $this->option('database');

        if ($database === '') {
            $this->components->error('Name the database to restore into with --database.');

            return self::INVALID;
        }

        if (! $this->option('force') && ! $this->confirm("Everything in the database \"{$database}\" will be replaced. Continue?")) {
            return self::FAILURE;
        }

        $work = storage_path('app/backup-temp/restore-'.Str::random(8));
        @mkdir($work, 0700, true);

        try {
            $zip = $this->source((string) $this->argument('source'), $work);
            $sql = $restorer->extractDump($zip, $work);
            $statements = $restorer->import($sql, $database);
            $tables = count($restorer->tables($database));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            foreach (glob($work.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($work);
        }

        $this->components->info("Restored into \"{$database}\": {$statements} statements, {$tables} tables.");

        return self::SUCCESS;
    }

    /** A local zip path, or the newest database backup downloaded from a destination. */
    private function source(string $source, string $work): string
    {
        if ($source !== 'latest') {
            if (! is_file($source)) {
                throw new \RuntimeException("{$source} does not exist.");
            }

            return $source;
        }

        $id = $this->option('destination');
        $destination = BackupDestination::query()
            ->when($id !== null, fn ($query) => $query->whereKey($id))
            ->orderBy('id')
            ->get()
            ->first(fn (BackupDestination $destination): bool => $destination->takes('db'))
            ?? throw new \RuntimeException('No backup destination holds database backups.');

        $download = app(Restorer::class)->downloadNewest($destination, $work);
        $this->components->info("Downloaded {$download['name']} from {$destination->name}.");

        return $download['path'];
    }
}
