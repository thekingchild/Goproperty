<?php

declare(strict_types=1);

namespace App\Installer\Migrations;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs pending migrations a few at a time, so each installer request stays
 * well inside shared hosts' time limits (INSTALLER.md § 5, step 4). Progress
 * comes from Laravel's own `migrations` table, so closing the tab or a
 * timeout only means the next batch carries on where this one stopped.
 */
final class BatchedMigrator
{
    private ?string $connection = null;

    public function __construct(
        private readonly Migrator $migrator,
        private readonly Config $config,
    ) {}

    /** A copy that migrates the named connection instead of the default. */
    public function on(?string $connection): self
    {
        $copy = clone $this;
        $copy->connection = $connection;

        return $copy;
    }

    public function progress(): MigrationProgress
    {
        return $this->using(fn (): MigrationProgress => $this->currentProgress());
    }

    /**
     * Runs up to $max migrations, stopping early once $seconds have passed
     * (at least one runs). A failure stops the batch and is reported with
     * the migration's name.
     */
    public function runBatch(?int $max = null, ?float $seconds = null): BatchResult
    {
        return $this->using(fn (): BatchResult => $this->batch($max, $seconds));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function using(callable $callback): mixed
    {
        return $this->connection === null ? $callback() : $this->migrator->usingConnection($this->connection, $callback);
    }

    private function currentProgress(): MigrationProgress
    {
        $files = $this->files();
        $ran = $this->migrator->repositoryExists() ? $this->migrator->getRepository()->getRan() : [];
        $pending = array_keys(array_diff_key($files, array_flip($ran)));

        return new MigrationProgress(count($files), count($files) - count($pending), $pending);
    }

    private function batch(?int $max, ?float $seconds): BatchResult
    {
        $max ??= $this->setting('installer.migrations_per_batch', 5);
        $seconds ??= (float) $this->setting('installer.batch_seconds', 20);

        if (! $this->migrator->repositoryExists()) {
            $this->migrator->getRepository()->createRepository();
        }

        $files = $this->files();
        $started = hrtime(true);
        $ran = [];

        foreach ($this->currentProgress()->pending as $name) {
            if (count($ran) >= $max || ($ran !== [] && (hrtime(true) - $started) / 1e9 >= $seconds)) {
                break;
            }

            try {
                $this->migrator->runPending([$files[$name]], ['step' => true]);
            } catch (Throwable $exception) {
                $message = self::clean($exception);
                Log::channel('install')->error('Migration failed', ['migration' => $name, 'error' => $message]);

                return new BatchResult($ran, $this->currentProgress(), $name, $message);
            }

            $ran[] = $name;
        }

        $progress = $this->currentProgress();
        Log::channel('install')->info('Migration batch', ['ran' => count($ran), 'done' => $progress->done, 'total' => $progress->total]);

        return new BatchResult($ran, $progress);
    }

    /** @return array<string, string> migration name => file, in run order */
    private function files(): array
    {
        $paths = array_values(array_unique([database_path('migrations'), ...$this->migrator->paths()]));

        return $this->migrator->getMigrationFiles($paths);
    }

    private function setting(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }

    /** The driver's message without Laravel's "(Connection: …, SQL: …)" suffix. */
    private static function clean(Throwable $exception): string
    {
        $message = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();

        return trim((string) preg_replace('/\s*\(Connection: .*$/s', '', $message));
    }
}
