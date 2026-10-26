<?php

declare(strict_types=1);

namespace App\Installer;

/**
 * Installer progress (INSTALLER.md § 3, § 5). The database row uses every
 * stage except `CleanupPending`, which only the checkpoint records: the
 * finalizer writes it first, so a failure later in finishing still leaves
 * the installer closed (recovery is via the CLI, § 9).
 */
enum InstallStage: string
{
    case Configured = 'configured';
    case Migrating = 'migrating';
    case OwnerReady = 'owner_ready';
    case CleanupPending = 'cleanup_pending';
    case Finalized = 'finalized';

    /** Position in the install, to move only forward. */
    public function order(): int
    {
        return match ($this) {
            self::Configured => 1,
            self::Migrating => 2,
            self::OwnerReady => 3,
            self::CleanupPending => 4,
            self::Finalized => 5,
        };
    }

    /** Past this point the installer is closed for good. */
    public function isFinished(): bool
    {
        return $this === self::CleanupPending || $this === self::Finalized;
    }
}
