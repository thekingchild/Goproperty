<?php

declare(strict_types=1);

namespace App\Backup;

use PDO;
use Spatie\DbDumper\Databases\MySql;

/**
 * Plugs App\Backup\PdoMySqlDump into spatie/laravel-backup in place of the
 * mysqldump binary, so backups work on shared hosts without exec.
 */
final class PhpMySqlDumper extends MySql
{
    public function dumpToFile(string $dumpFile): void
    {
        $dsn = $this->socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $this->socket, $this->dbName)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->dbName);

        (new PdoMySqlDump(new PDO($dsn, $this->userName, $this->password)))->toFile($dumpFile, array_values($this->includeTables), array_values($this->excludeTables));
    }
}
