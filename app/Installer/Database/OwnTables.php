<?php

declare(strict_types=1);

namespace App\Installer\Database;

use Illuminate\Database\Migrations\Migrator;

/**
 * The tables GoProperty's migrations create, read from the migration files
 * (`Schema::create('name'`). No hand-kept list: AGENT.md § 3 rule 11 keeps
 * one literal table name per create, and a test checks that a migrated
 * database holds nothing else.
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
}
