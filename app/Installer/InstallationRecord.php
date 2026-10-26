<?php

declare(strict_types=1);

namespace App\Installer;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * The `installation_state` row (id 1), the database marker of
 * INSTALLER.md § 3. Not final: tests stand in an unreadable database.
 */
class InstallationRecord
{
    public const string TABLE = 'installation_state';

    /**
     * Null when the table or row does not exist yet. Other database errors
     * (unreachable server, wrong password) are thrown.
     */
    public function stage(ConnectionInterface $connection): ?InstallStage
    {
        try {
            $status = $connection->table(self::TABLE)->where('id', 1)->value('status');
        } catch (QueryException $exception) {
            if (self::isMissingTable($exception)) {
                return null;
            }

            throw $exception;
        }

        return is_string($status) ? InstallStage::tryFrom($status) : null;
    }

    public function record(ConnectionInterface $connection, InstallStage $stage, string $version): void
    {
        $now = now();

        $connection->table(self::TABLE)->upsert(
            [[
                'id' => 1,
                'status' => $stage->value,
                'version' => $version,
                'finalized_at' => $stage === InstallStage::Finalized ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['id'],
            ['status', 'version', 'finalized_at', 'updated_at'],
        );
    }

    public static function isMissingTable(QueryException $exception): bool
    {
        // SQLSTATE 42S02: base table or view not found (MySQL and MariaDB).
        return $exception->getCode() === '42S02';
    }
}
