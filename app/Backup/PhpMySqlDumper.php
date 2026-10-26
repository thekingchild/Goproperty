<?php

declare(strict_types=1);

namespace App\Backup;

use Druidfi\Mysqldump\Mysqldump;
use Spatie\DbDumper\Databases\MySql;

/**
 * Dumps MySQL/MariaDB in PHP over PDO (druidfi/mysqldump-php), so backups
 * work on shared hosts that disable exec and proc_open (ARCHITECTURE.md
 * § 12). Plugged into spatie/laravel-backup in place of mysqldump.
 */
final class PhpMySqlDumper extends MySql
{
    public function dumpToFile(string $dumpFile): void
    {
        $dsn = $this->socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s', $this->socket, $this->dbName)
            : sprintf('mysql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $this->dbName);

        $dumper = new Mysqldump($dsn, $this->userName, $this->password, [
            'add-drop-table' => true,
            'single-transaction' => true,
            'lock-tables' => false,
            'add-locks' => false,
            'default-character-set' => 'utf8mb4',
            'hex-blob' => true,
            'routines' => false,
            'events' => false,
            'skip-definer' => true,
            'skip-dump-date' => false,
            'include-tables' => $this->includeTables,
            'exclude-tables' => $this->excludeTables,
        ]);

        $dumper->start($dumpFile);
    }
}
