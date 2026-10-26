<?php

declare(strict_types=1);

namespace App\Installer\Database;

use App\Installer\InstallationRecord;
use App\Installer\InstallStage;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * "Test connection" for the installer's database step (INSTALLER.md § 5–6):
 * a temporary connection with a 3-second timeout that checks the server
 * version and refuses a database that already has tables, unless they are
 * GoProperty's own from an earlier attempt that never finished. Nothing is
 * written, and messages are cleaned of the host, username and password.
 */
final class DatabaseProbe
{
    public const string CONNECTION = 'installer_probe';

    /** Oldest supported servers (ARCHITECTURE.md § 2). */
    public const string MINIMUM_MYSQL = '8.0.0';

    public const string MINIMUM_MARIADB = '10.6.0';

    private const int LISTED_TABLES = 5;

    public function __construct(
        private readonly Config $config,
        private readonly DatabaseManager $db,
        private readonly OwnTables $ownTables,
        private readonly InstallationRecord $record,
    ) {}

    public function test(DatabaseSettings $settings): ProbeResult
    {
        try {
            $connection = $this->connect($settings);
            $version = $this->serverVersion($connection);
            $mariaDb = stripos($version, 'mariadb') !== false;
            $minimum = $mariaDb ? self::MINIMUM_MARIADB : self::MINIMUM_MYSQL;

            if (version_compare(self::numericVersion($version), $minimum, '<')) {
                return new ProbeResult(false, sprintf(
                    'This server runs %s %s; GoProperty needs %s %s or newer.',
                    $mariaDb ? 'MariaDB' : 'MySQL', self::numericVersion($version), $mariaDb ? 'MariaDB' : 'MySQL', substr($minimum, 0, -2),
                ), $version, $mariaDb);
            }

            // Name the schema: without it MySQL lists every database this
            // user can see, which on shared hosts includes other sites'.
            $tables = $connection->getSchemaBuilder()->getTableListing($settings->database, false);
            $foreign = array_values(array_diff($tables, $this->ownTables->names()));

            if ($foreign !== []) {
                return new ProbeResult(false, 'This database already has tables that are not GoProperty\'s ('
                    .self::list($foreign).'). Choose an empty database.', $version, $mariaDb, $tables);
            }

            if (in_array(InstallationRecord::TABLE, $tables, true)
                && $this->record->stage($connection) === InstallStage::Finalized) {
                return new ProbeResult(false, 'This database holds a finished GoProperty installation. Choose an empty database.', $version, $mariaDb, $tables);
            }

            return new ProbeResult(true, $tables === []
                ? 'Connected. The database is empty.'
                : 'Connected. The database holds GoProperty tables from an earlier attempt; the install will continue from there.',
                $version, $mariaDb, $tables);
        } catch (Throwable $exception) {
            return new ProbeResult(false, self::explain($exception, $settings));
        } finally {
            $this->db->purge(self::CONNECTION);
        }
    }

    /**
     * Maps driver errors to plain advice. Anything else is shown with the
     * host, username and password removed.
     */
    public static function explain(Throwable $exception, DatabaseSettings $settings): string
    {
        $message = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();
        $code = preg_match('/\[(\d{4})\]/', $message, $match) === 1 ? (int) $match[1] : 0;

        return match (true) {
            $code === 1045 => 'The username or password was refused.',
            $code === 1044, $code === 1142 => 'This user has no access to that database. Give the user ALL PRIVILEGES on it (cPanel → MySQL Databases → Add User To Database; Plesk → Databases → User Management).',
            $code === 1049 => 'That database does not exist. Create it first (cPanel → MySQL Databases; Plesk → Databases).',
            in_array($code, [2002, 2003, 2005, 2006], true),
            preg_match('/timed out|getaddrinfo|Connection refused|No such file or directory|Name or service not known/i', $message) === 1 => 'Cannot reach a database server at that host and port. On cPanel and Plesk the host is usually "localhost" and the port 3306.',
            default => 'The database server said: '.self::clean($settings->scrub($message)),
        };
    }

    private function connect(DatabaseSettings $settings): Connection
    {
        $base = $this->config->get('database.connections.'.$settings->driver);
        $timeout = $this->config->get('installer.database_timeout', 3);

        $this->db->purge(self::CONNECTION);
        $this->config->set(
            'database.connections.'.self::CONNECTION,
            $settings->connectionConfig(is_array($base) ? $base : [], is_numeric($timeout) ? (int) $timeout : 3),
        );

        $connection = $this->db->connection(self::CONNECTION);
        $connection->getPdo();

        return $connection;
    }

    private function serverVersion(Connection $connection): string
    {
        $row = $connection->selectOne('select version() as version');

        return is_object($row) && isset($row->version) && is_string($row->version) ? $row->version : '';
    }

    /** "10.11.6-MariaDB-0+deb12u1" → "10.11.6"; "5.5.5-10.6.12-MariaDB" → "10.6.12". */
    public static function numericVersion(string $version): string
    {
        $version = (string) preg_replace('/^5\.5\.5-/', '', $version);

        return preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $version, $match) === 1 ? $match[1] : '0';
    }

    /** Drops Laravel's "(Connection: …, SQL: …)" suffix. */
    private static function clean(string $message): string
    {
        return trim((string) preg_replace('/\s*\(Connection: .*$/s', '', $message));
    }

    /** @param  list<string>  $tables */
    private static function list(array $tables): string
    {
        $shown = implode(', ', array_slice($tables, 0, self::LISTED_TABLES));
        $more = count($tables) - self::LISTED_TABLES;

        return $more > 0 ? "{$shown} and {$more} more" : $shown;
    }
}
