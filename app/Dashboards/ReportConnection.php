<?php

declare(strict_types=1);

namespace App\Dashboards;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;

/**
 * The read-replica-ready connection of the dashboards' queries (P08-04,
 * ARCHITECTURE.md § 13; `database.reports` in config/database.php).
 *
 * The reports read the other modules through their contracts, whose
 * models use the default connection, so a report's work is run with the
 * default connection switched to `reports` and back (reads only). With no
 * replica configured nothing is switched: the reports read the main
 * connection, which is also what keeps the test suite (one database, its
 * writes in an open transaction) seeing its own records.
 *
 * Replica lag: for `primary_after_write` seconds after the organization's
 * figures last changed (its DashboardGeneration), its reports read from
 * the main database, so what gets cached under the new generation already
 * holds the write. The `reports` connection sends any write to the main
 * database and is sticky within a request.
 */
final readonly class ReportConnection
{
    public const string NAME = 'reports';

    public function __construct(
        private Repository $config,
        private DatabaseManager $db,
    ) {}

    public function replicaConfigured(): bool
    {
        $host = $this->config->get('database.reports.read_host');

        return is_string($host) && trim($host) !== '';
    }

    /** The connection the reports of figures at this generation read from. */
    public function nameFor(DashboardGeneration $generation): string
    {
        $main = $this->db->getDefaultConnection();

        if (! $this->replicaConfigured() || $generation->age() < $this->primaryAfterWrite()) {
            return $main;
        }

        $this->define($main);

        return self::NAME;
    }

    /**
     * Runs a report's reads on the connection for this generation.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $read
     * @return TValue
     */
    public function run(DashboardGeneration $generation, Closure $read): mixed
    {
        $main = $this->db->getDefaultConnection();
        $name = $this->nameFor($generation);

        if ($name === $main) {
            return $read();
        }

        $this->db->setDefaultConnection($name);

        try {
            return $read();
        } finally {
            $this->db->setDefaultConnection($main);
        }
    }

    private function primaryAfterWrite(): int
    {
        $seconds = $this->config->get('database.reports.primary_after_write');

        return is_numeric($seconds) ? max(0, (int) $seconds) : 60;
    }

    /** The `reports` connection: the main one, its reads from the replica. */
    private function define(string $main): void
    {
        if ($main === self::NAME || $this->config->has('database.connections.'.self::NAME)) {
            return;
        }

        $base = $this->config->get('database.connections.'.$main);
        $read = array_filter([
            'host' => $this->config->get('database.reports.read_host'),
            'port' => $this->config->get('database.reports.read_port'),
            'username' => $this->config->get('database.reports.read_username'),
            'password' => $this->config->get('database.reports.read_password'),
        ], static fn (mixed $value): bool => is_scalar($value) && (string) $value !== '');

        $this->config->set('database.connections.'.self::NAME, [
            ...(is_array($base) ? $base : []),
            'read' => $read,
            'write' => [],
            'sticky' => true,
        ]);
    }
}
