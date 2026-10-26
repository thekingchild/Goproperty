<?php

declare(strict_types=1);

namespace App\Backup;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Backup\BackupDestination\BackupDestination as SpatieDestination;
use ZipArchive;

/**
 * Opens a database backup and loads it into another database
 * (PLATFORM_CONSOLE.md § 4, docs/runbooks/restore.md). Pure PHP: the zip is
 * decrypted with the archive password and the dump is replayed statement
 * by statement over PDO, so no mysql client is needed. It never writes to
 * the site's own database: production restores go into a new database
 * that .env is then pointed at.
 */
final readonly class Restorer
{
    public const string CONNECTION = 'backup_restore';

    public function __construct(
        private ArchivePassword $password,
        private DatabaseManager $db,
        private Config $config,
    ) {}

    /**
     * Downloads the newest database backup at a destination into $workDirectory.
     *
     * @return array{path: string, name: string}
     */
    public function downloadNewest(BackupDestination $destination, string $workDirectory): array
    {
        $this->config->set('filesystems.disks.'.$destination->diskName(), $destination->diskConfig());
        $newest = SpatieDestination::create($destination->diskName(), 'goproperty-db')->newestBackup()
            ?? throw new RuntimeException("There is no database backup at {$destination->name} yet.");

        $local = rtrim($workDirectory, '/').'/'.basename($newest->path());
        $in = $newest->stream();
        $out = fopen($local, 'wb');

        if ($out === false) {
            throw new RuntimeException("{$local} cannot be written.");
        }

        stream_copy_to_stream($in, $out);
        fclose($out);

        return ['path' => $local, 'name' => $newest->path()];
    }

    /** Decrypts the zip and returns the path of its SQL dump. */
    public function extractDump(string $zipPath, string $workDirectory): string
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("{$zipPath} is not a backup zip.");
        }

        $zip->setPassword($this->password->get());
        $entry = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (str_starts_with($name, 'db-dumps/') && str_ends_with($name, '.sql')) {
                $entry = $name;
            }
        }

        if ($entry === null) {
            $zip->close();

            throw new RuntimeException('The zip holds no database dump: is it a files backup?');
        }

        $target = rtrim($workDirectory, '/').'/'.Str::random(12).'.sql';
        $in = $zip->getStream($entry);
        $out = fopen($target, 'wb');

        if ($in === false || $out === false) {
            $zip->close();

            throw new RuntimeException('The backup could not be decrypted: wrong APP_KEY or BACKUP_ARCHIVE_PASSWORD?');
        }

        $copied = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        $zip->close();

        if ($copied === false || $copied === 0 || ! $this->looksLikeSql($target)) {
            @unlink($target);

            throw new RuntimeException('The backup could not be decrypted: wrong APP_KEY or BACKUP_ARCHIVE_PASSWORD?');
        }

        return $target;
    }

    /**
     * Replays a dump into $database, after removing every table there.
     *
     * @return int statements run
     */
    public function import(string $sqlPath, string $database): int
    {
        $live = (string) $this->config->get('database.connections.'.$this->config->get('database.default').'.database');

        if ($database === '' || $database === $live) {
            throw new RuntimeException('Refusing to restore into the site\'s own database. Restore into a new database and point DB_DATABASE at it.');
        }

        $connection = $this->connectionTo($database);
        $this->emptyDatabase($connection);

        $handle = fopen($sqlPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("{$sqlPath} cannot be read.");
        }

        $statement = '';
        $count = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);

                if ($statement === '' && ($trimmed === '' || str_starts_with($trimmed, '--'))) {
                    continue;
                }

                $statement .= $line;

                // A dump ends each statement with ";" at the end of a line;
                // values never span lines (newlines inside strings are escaped).
                if (str_ends_with($trimmed, ';')) {
                    // The dump is SQL text by nature; replayed as written.
                    $connection->getPdo()->exec($statement);
                    $statement = '';
                    $count++;
                }
            }

            if (trim($statement) !== '') {
                $connection->getPdo()->exec($statement);
                $count++;
            }
        } finally {
            fclose($handle);
            $this->db->purge(self::CONNECTION);
        }

        return $count;
    }

    /** @return list<string> */
    public function tables(string $database): array
    {
        $tables = array_map(fn (array $table): string => (string) $table['name'], $this->connectionTo($database)->getSchemaBuilder()->getTables($database));
        $this->db->purge(self::CONNECTION);
        sort($tables);

        return $tables;
    }

    private function connectionTo(string $database): Connection
    {
        $base = (array) $this->config->get('database.connections.'.$this->config->get('database.default'));
        $this->config->set('database.connections.'.self::CONNECTION, ['database' => $database] + $base);
        $this->db->purge(self::CONNECTION);

        return $this->db->connection(self::CONNECTION);
    }

    private function emptyDatabase(Connection $connection): void
    {
        $pdo = $connection->getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        foreach ($connection->getSchemaBuilder()->getTables($connection->getDatabaseName()) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `'.str_replace('`', '``', (string) $table['name']).'`');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function looksLikeSql(string $path): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 4096);

        return str_contains($head, '-- ') || str_contains($head, '/*!') || str_contains($head, 'CREATE TABLE') || str_contains($head, 'SET ');
    }
}
