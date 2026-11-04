<?php

declare(strict_types=1);

namespace App\Installer\Upgrades;

enum UpgradeStepKind: string
{
    /** A batch of migrations ran; more requests follow. */
    case Migrated = 'migrated';

    /** A migration failed: the site stays in maintenance mode. */
    case MigrationFailed = 'migration_failed';

    /** The install seeders ran and the caches were rebuilt: the upgrade is done. */
    case Finished = 'finished';

    /** The seeders or the caches failed: the site stays in maintenance mode. */
    case FinishFailed = 'finish_failed';
}
