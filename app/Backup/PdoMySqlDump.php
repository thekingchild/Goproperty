<?php

declare(strict_types=1);

namespace App\Backup;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * A MySQL/MariaDB dump in plain PHP over PDO (ARCHITECTURE.md § 12: no
 * exec or proc_open on shared hosting). One consistent snapshot; each
 * table as DROP + CREATE, then its rows as multi-row INSERTs.
 *
 * Every statement ends with ";" at the end of a line, and values never
 * contain a raw line break (strings are escaped, binary is hex), which is
 * what App\Backup\Restorer relies on when replaying the file. Generated
 * columns are left out of the INSERTs.
 */
final readonly class PdoMySqlDump
{
    /** Rough size of one INSERT statement before a new one starts. */
    private const int STATEMENT_BYTES = 1_000_000;

    public function __construct(private PDO $pdo) {}

    /**
     * @param  list<string>  $only  table names to dump (all when empty)
     * @param  list<string>  $except  table names to leave out
     */
    public function toFile(string $path, array $only = [], array $except = []): void
    {
        $out = fopen($path, 'wb');

        if ($out === false) {
            throw new RuntimeException("{$path} cannot be written.");
        }

        try {
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec("SET NAMES utf8mb4, time_zone = '+00:00'");
            $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

            $database = (string) $this->query('SELECT DATABASE()')->fetchColumn();
            fwrite($out, "-- GoProperty database dump of `{$database}`, ".gmdate('Y-m-d H:i:s')." UTC\n");
            fwrite($out, "SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

            foreach ($this->tables($only, $except) as $table) {
                $this->dumpTable($out, $table);
            }

            fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n-- Dump completed\n");
            $this->pdo->exec('COMMIT');
        } finally {
            fclose($out);
        }
    }

    /**
     * @param  list<string>  $only
     * @param  list<string>  $except
     * @return list<string>
     */
    private function tables(array $only, array $except): array
    {
        $tables = [];

        foreach ($this->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $row) {
            $name = (string) $row[0];

            if (($only === [] || in_array($name, $only, true)) && ! in_array($name, $except, true)) {
                $tables[] = $name;
            }
        }

        sort($tables);

        return $tables;
    }

    /** @param  resource  $out */
    private function dumpTable($out, string $table): void
    {
        $quoted = $this->identifier($table);
        $definition = $this->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM);
        $create = is_array($definition) ? (string) ($definition[1] ?? '') : '';

        fwrite($out, "DROP TABLE IF EXISTS {$quoted};\n{$create};\n");

        $columns = $this->insertableColumns($table);

        if ($columns === []) {
            fwrite($out, "\n");

            return;
        }

        $list = implode(', ', array_map($this->identifier(...), $columns));
        // Unbuffered: rows stream from the server instead of filling memory.
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $select = $this->query('SELECT '.$list.' FROM '.$quoted);
        $prefix = "INSERT INTO {$quoted} ({$list}) VALUES ";
        $rows = [];
        $bytes = 0;

        while (($row = $select->fetch(PDO::FETCH_NUM)) !== false) {
            $values = '('.implode(',', array_map($this->value(...), $row)).')';
            $rows[] = $values;
            $bytes += strlen($values);

            if ($bytes >= self::STATEMENT_BYTES) {
                fwrite($out, $prefix.implode(',', $rows).";\n");
                $rows = [];
                $bytes = 0;
            }
        }

        $select->closeCursor();
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

        if ($rows !== []) {
            fwrite($out, $prefix.implode(',', $rows).";\n");
        }

        fwrite($out, "\n");
    }

    /** @return list<string> columns that take values (not generated) */
    private function insertableColumns(string $table): array
    {
        $statement = $this->pdo->prepare('SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $statement->execute([$table]);
        $columns = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (! str_contains(strtoupper((string) ($column['EXTRA'] ?? '')), 'GENERATED')) {
                $columns[] = (string) $column['COLUMN_NAME'];
            }
        }

        return $columns;
    }

    private function value(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            // Bytes that are not text go as hex, so nothing breaks a line.
            ! mb_check_encoding((string) $value, 'UTF-8') => '0x'.bin2hex((string) $value),
            default => (string) $this->pdo->quote((string) $value),
        };
    }

    private function query(string $sql): PDOStatement
    {
        $statement = $this->pdo->query($sql);

        if (! $statement instanceof PDOStatement) {
            throw new RuntimeException('The database refused: '.$sql);
        }

        return $statement;
    }

    private function identifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }
}
