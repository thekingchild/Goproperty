<?php

declare(strict_types=1);

namespace App\Installer\Database;

use Illuminate\Database\ConnectionInterface;

/**
 * A database's structure from `information_schema`: tables, columns,
 * indexes, foreign keys and the migrations that ran, with no data and no
 * auto-increment counters. A database built by the installer must give the
 * same snapshot as one built by a plain `migrate` (INSTALLER.md § 12).
 */
final class SchemaSnapshot
{
    /** @return array{tables: array<string, array<string, mixed>>, migrations: list<string>} */
    public function take(ConnectionInterface $connection): array
    {
        $schema = $connection->getDatabaseName();

        $base = $connection->select(
            "select table_name as name, engine, table_collation as collation from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE' order by table_name",
            [$schema],
        );

        // information_schema.columns also lists views; only base tables are read below.
        $columns = self::byTable($connection->select(
            'select table_name as t, column_name as name, column_type as type, is_nullable as nullable, '
            .'column_default as `default`, extra, character_set_name as charset, collation_name as collation '
            .'from information_schema.columns where table_schema = ? order by table_name, ordinal_position',
            [$schema],
        ));

        $indexes = self::byTable($connection->select(
            'select table_name as t, index_name as name, non_unique, column_name as col, sub_part, index_type as type '
            .'from information_schema.statistics where table_schema = ? order by table_name, index_name, seq_in_index',
            [$schema],
        ));

        $foreignKeys = self::byTable($connection->select(
            'select k.table_name as t, k.constraint_name as name, k.column_name as col, k.referenced_table_name as ref_table, '
            .'k.referenced_column_name as ref_col, r.update_rule, r.delete_rule '
            .'from information_schema.key_column_usage k join information_schema.referential_constraints r '
            .'on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name '
            .'where k.table_schema = ? and k.referenced_table_name is not null order by k.table_name, k.constraint_name, k.ordinal_position',
            [$schema],
        ));

        $tables = [];

        foreach ($base as $row) {
            $name = self::field($row, 'name');

            $tables[$name] = [
                'engine' => self::field($row, 'engine'),
                'collation' => self::field($row, 'collation'),
                'columns' => array_map(fn (object $column): array => [
                    'name' => self::field($column, 'name'),
                    'type' => self::field($column, 'type'),
                    'nullable' => self::field($column, 'nullable'),
                    'default' => self::field($column, 'default'),
                    'extra' => self::field($column, 'extra'),
                    'charset' => self::field($column, 'charset'),
                    'collation' => self::field($column, 'collation'),
                ], $columns[$name] ?? []),
                'indexes' => self::indexes($indexes[$name] ?? []),
                'foreign_keys' => self::foreignKeys($foreignKeys[$name] ?? []),
            ];
        }

        $migrations = isset($tables['migrations'])
            ? array_map(fn (object $row): string => self::field($row, 'migration'), $connection->select('select migration from migrations order by migration'))
            : [];

        return ['tables' => $tables, 'migrations' => array_values($migrations)];
    }

    /**
     * @param  array<mixed>  $rows
     * @return array<string, list<object>>
     */
    private static function byTable(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            if (is_object($row)) {
                $grouped[self::field($row, 't')][] = $row;
            }
        }

        return $grouped;
    }

    /**
     * @param  list<object>  $rows  ordered by index name, then position
     * @return array<string, array{unique: bool, type: string, columns: list<string>}>
     */
    private static function indexes(array $rows): array
    {
        $indexes = [];

        foreach ($rows as $row) {
            $name = self::field($row, 'name');
            $subPart = self::field($row, 'sub_part');

            $indexes[$name] = [
                'unique' => self::field($row, 'non_unique') === '0',
                'type' => self::field($row, 'type'),
                'columns' => [...($indexes[$name]['columns'] ?? []), self::field($row, 'col').($subPart !== '' ? "({$subPart})" : '')],
            ];
        }

        ksort($indexes, SORT_STRING);

        return $indexes;
    }

    /**
     * @param  list<object>  $rows  ordered by constraint name, then position
     * @return array<string, array{references: string, on_update: string, on_delete: string, columns: list<string>}>
     */
    private static function foreignKeys(array $rows): array
    {
        $keys = [];

        foreach ($rows as $row) {
            $name = self::field($row, 'name');

            $keys[$name] = [
                'references' => self::field($row, 'ref_table'),
                'on_update' => self::field($row, 'update_rule'),
                'on_delete' => self::field($row, 'delete_rule'),
                'columns' => [...($keys[$name]['columns'] ?? []), self::field($row, 'col').' → '.self::field($row, 'ref_col')],
            ];
        }

        ksort($keys, SORT_STRING);

        return $keys;
    }

    /** Column names come back in either case depending on the server. */
    private static function field(object $row, string $name): string
    {
        $values = array_change_key_case((array) $row, CASE_LOWER);
        $value = $values[$name] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
