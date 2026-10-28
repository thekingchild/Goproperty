<?php

declare(strict_types=1);

namespace App\Installer\Database;

use Illuminate\Database\Migrations\Migrator;

/**
 * The tables GoProperty's migrations create, read from the migration files
 * (`Schema::create('name'`). No hand-kept list: AGENT.md § 3 rule 11 keeps
 * one literal table name per create, and a test checks that a migrated
 * database holds nothing else.
 *
 * names() is every table any migration creates (a database part-way
 * through its migrations may hold one a later migration drops, such as
 * P02-04's `billing_transfer_requests`, dropped in P03-14); expected() is
 * what a fully migrated database holds: tables created in a migration's
 * up() and not dropped by a later one's.
 */
final class OwnTables
{
    public function __construct(private readonly Migrator $migrator) {}

    /** @return list<string> */
    public function names(): array
    {
        $paths = array_values(array_unique([database_path('migrations'), ...$this->migrator->paths()]));
        $repository = config('database.migrations.table', 'migrations');
        $tables = [is_string($repository) ? $repository : 'migrations'];

        foreach ($this->migrator->getMigrationFiles($paths) as $file) {
            preg_match_all('/Schema::(?:connection\([^)]*\)->)?create\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $table) {
                $tables[] = $table;
            }
        }

        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }

    /** @return list<string> */
    public function expected(): array
    {
        $paths = array_values(array_unique([database_path('migrations'), ...$this->migrator->paths()]));
        $repository = config('database.migrations.table', 'migrations');
        $tables = [is_string($repository) ? $repository : 'migrations' => true];
        $files = $this->migrator->getMigrationFiles($paths);
        ksort($files);

        foreach ($files as $file) {
            $up = self::upOf((string) file_get_contents($file));
            preg_match_all('/Schema::(?:connection\([^)]*\)->)?(create|dropIfExists|drop)\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $up, $matches, PREG_SET_ORDER);

            foreach ($matches as [, $verb, $table]) {
                if ($verb === 'create') {
                    $tables[$table] = true;
                } else {
                    unset($tables[$table]);
                }
            }
        }

        $names = array_map('strval', array_keys($tables));
        sort($names);

        return $names;
    }

    /** The body of a migration's up() (everything before its down()). */
    private static function upOf(string $source): string
    {
        $start = strpos($source, 'function up(');
        $end = strpos($source, 'function down(');

        if ($start === false) {
            return $source;
        }

        return $end !== false && $end > $start ? substr($source, $start, $end - $start) : substr($source, $start);
    }
}
