<?php

declare(strict_types=1);

namespace App\Installer\Migrations;

use App\Installer\Database\OwnTables;
use App\Installer\InstallationMarkers;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * "Empty the database and start this step again" (INSTALLER.md § 6). MySQL
 * cannot roll back schema changes, so a migration that fails halfway on a
 * brand-new install is fixed by dropping GoProperty's own tables and
 * migrating again. Only while the installer is open, never once an
 * organization exists, and only after the owner types the database name.
 * Other tables in the same database are never touched.
 */
final class FreshDatabaseReset
{
    public function __construct(
        private readonly InstallationMarkers $markers,
        private readonly OwnTables $ownTables,
        private readonly DatabaseManager $db,
    ) {}

    /** @return list<string> the tables dropped */
    public function reset(string $confirmDatabaseName, ?string $connectionName = null): array
    {
        if (! $this->markers->installerOpen()) {
            throw new RuntimeException('The installer is closed. Recovery is from the command line (INSTALLER.md § 9).');
        }

        $connection = $this->db->connection($connectionName);
        $database = $connection->getDatabaseName();

        if (! hash_equals($database, $confirmDatabaseName)) {
            throw new InvalidArgumentException('Type the database name exactly to confirm.');
        }

        $schema = $connection->getSchemaBuilder();
        $existing = $schema->getTableListing($database, false);
        $tables = array_values(array_intersect($existing, $this->ownTables->names()));

        if (in_array('organizations', $existing, true) && $connection->table('organizations')->exists()) {
            throw new RuntimeException('Organizations exist in this database, so it is not a new install. Nothing was dropped.');
        }

        $schema->withoutForeignKeyConstraints(function () use ($schema, $tables): void {
            foreach ($tables as $table) {
                $schema->drop($table);
            }
        });

        Log::channel('install')->warning('Emptied the database for a fresh install', ['tables' => count($tables)]);

        return $tables;
    }
}
