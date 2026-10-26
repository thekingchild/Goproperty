<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Installer\Database\SchemaSnapshot;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

/**
 * Prints the database structure as JSON, for the install drill's "installer
 * schema = plain migrate schema" comparison (scripts/release/install-drill.sh).
 */
final class SchemaSnapshotCommand extends Command
{
    protected $signature = 'goproperty:schema-snapshot {--connection= : Connection name (default: the default connection)}';

    protected $description = 'Print the database structure as JSON (install drill)';

    protected $hidden = true;

    public function handle(DatabaseManager $db, SchemaSnapshot $snapshot): int
    {
        $name = $this->option('connection');

        $this->line((string) json_encode(
            $snapshot->take($db->connection(is_string($name) && $name !== '' ? $name : null)),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return self::SUCCESS;
    }
}
