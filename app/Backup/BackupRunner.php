<?php

declare(strict_types=1);

namespace App\Backup;

use App\Models\PlatformStaff;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Backup\BackupDestination\BackupDestinationFactory;
use Spatie\Backup\Config\Config as BackupConfig;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Notifications\EventHandler;
use Spatie\Backup\Tasks\Backup\BackupJobFactory;
use Spatie\Backup\Tasks\Backup\DbDumperFactory;
use Spatie\Backup\Tasks\Cleanup\CleanupJob;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Throwable;

/**
 * Runs one backup job, "db" or "files" (PLATFORM_CONSOLE.md § 4), to every
 * active destination that takes it, then applies retention there. Runs
 * in-process from the scheduler: no exec, proc_open or signals, so it works
 * on shared hosting. Each destination gets a backup_runs row.
 */
final readonly class BackupRunner
{
    public const array TYPES = ['db', 'files'];

    public function __construct(
        private Config $config,
        private Dispatcher $events,
        private ArchivePassword $password,
    ) {}

    /**
     * @return Collection<int, BackupRun> one row per destination
     */
    public function run(string $type, string $triggeredBy = 'schedule', ?PlatformStaff $staff = null): Collection
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown backup type {$type}.");
        }

        $destinations = BackupDestination::query()->orderBy('id')->get()->filter(fn (BackupDestination $destination): bool => $destination->takes($type))->values();
        $base = ['type' => $type, 'triggered_by' => $triggeredBy, 'triggered_by_staff_id' => $staff?->id, 'started_at' => now()];

        if ($destinations->isEmpty()) {
            Log::critical("Backup {$type} did not run: no active backup destination.");

            return collect([BackupRun::query()->create($base + ['status' => 'failed', 'finished_at' => now(), 'notes' => 'No active backup destination.'])]);
        }

        $runs = $destinations->mapWithKeys(fn (BackupDestination $destination): array => [
            $destination->diskName() => BackupRun::query()->create($base + ['backup_destination_id' => $destination->id, 'status' => 'running']),
        ]);

        foreach ($destinations as $destination) {
            $this->config->set('filesystems.disks.'.$destination->diskName(), $destination->diskConfig());
        }

        $this->useDumper();
        // Spatie empties its temporary folder when a job starts: give each run
        // its own, so a manual run cannot wipe the nightly one's zip.
        $temporary = storage_path('app/backup-temp/'.$type.'-'.Str::random(12));
        $config = $this->configFor($type, $temporary, array_values($destinations->map(fn (BackupDestination $destination): string => $destination->diskName())->all()));

        $succeeded = [];
        $failed = [];
        $listeners = [
            BackupWasSuccessful::class => function (BackupWasSuccessful $event) use (&$succeeded): void {
                $succeeded[$event->diskName] = true;
            },
            BackupHasFailed::class => function (BackupHasFailed $event) use (&$failed): void {
                $failed[$event->diskName ?? '*'] = $event->exception->getMessage();
            },
        ];

        foreach ($listeners as $event => $listener) {
            $this->events->listen($event, $listener);
        }

        EventHandler::disable();

        // Spatie's zip and encryption steps read the config from the container.
        $previous = app()->bound(BackupConfig::class) ? app(BackupConfig::class) : null;
        app()->instance(BackupConfig::class, $config);
        // Its job takes the temporary folder from the config repository, not
        // from $config, so point that at this run's folder too.
        $previousTemporary = $this->config->get('backup.backup.temporary_directory');
        $this->config->set('backup.backup.temporary_directory', $temporary);

        try {
            $job = BackupJobFactory::createFromConfig($config)->disableSignals();
            $type === 'db' ? $job->dontBackupFilesystem() : $job->dontBackupDatabases();
            $job->run();
        } catch (Throwable $exception) {
            $failed['*'] = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();
        } finally {
            foreach (array_keys($listeners) as $event) {
                $this->events->forget($event);
            }

            $previous !== null ? app()->instance(BackupConfig::class, $previous) : app()->forgetInstance(BackupConfig::class);
            $this->config->set('backup.backup.temporary_directory', $previousTemporary);
        }

        foreach ($runs as $disk => $run) {
            $this->finish($run, $disk, $config, $succeeded[$disk] ?? false, $failed[$disk] ?? $failed['*'] ?? null);
        }

        $this->cleanUp($config);
        File::deleteDirectory($temporary);

        return $runs->values();
    }

    /** @param  list<string>  $disks */
    private function configFor(string $type, string $temporary, array $disks): BackupConfig
    {
        $data = (array) $this->config->get('backup');
        $data['backup']['temporary_directory'] = $temporary;
        $data['backup']['name'] = 'goproperty-'.$type;
        $data['backup']['destination']['disks'] = $disks;
        $data['backup']['password'] = $this->password->get();

        return BackupConfig::fromArray($data);
    }

    private function useDumper(): void
    {
        if ($this->config->get('backup.dumper', 'php') !== 'php') {
            return;
        }

        foreach (['mysql', 'mariadb'] as $driver) {
            DbDumperFactory::extend($driver, fn (): PhpMySqlDumper => new PhpMySqlDumper);
        }
    }

    private function finish(BackupRun $run, string $disk, BackupConfig $config, bool $ok, ?string $error): void
    {
        if (! $ok) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'notes' => $error ?? 'The backup did not reach this destination.']);
            Log::critical("Backup {$run->type} failed at {$disk}.", ['run' => $run->id, 'error' => $error]);

            return;
        }

        $newest = BackupDestinationFactory::createFromArray($config)->first(fn ($destination): bool => $destination->diskName() === $disk)?->newestBackup();

        $run->update([
            'status' => 'success',
            'finished_at' => now(),
            'filename' => $newest?->path(),
            'size_bytes' => $newest?->sizeInBytes(),
        ]);
    }

    /** Retention at every destination (30 daily, 12 monthly). */
    private function cleanUp(BackupConfig $config): void
    {
        try {
            (new CleanupJob(BackupDestinationFactory::createFromArray($config), new DefaultStrategy($config)))->run();
        } catch (Throwable $exception) {
            Log::error('Backup clean-up failed.', ['error' => $exception->getMessage()]);
        }
    }
}
