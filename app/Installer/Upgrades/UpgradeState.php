<?php

declare(strict_types=1);

namespace App\Installer\Upgrades;

use App\Installer\AppVersion;
use App\Installer\Migrations\MigrationProgress;

/**
 * Where an installation stands after new code was uploaded (INSTALLER.md
 * § 10): the release the code says, the release whose install seeders last
 * ran against this database (`installation_state.version`), and the
 * migrations not run yet.
 */
final readonly class UpgradeState
{
    public function __construct(
        public string $codeVersion,
        public ?string $databaseVersion,
        public MigrationProgress $migrations,
    ) {}

    /**
     * The release's install seeders have not run yet. Only judged for a
     * release number: a checkout or deploy archive says "dev".
     */
    public function seedersPending(): bool
    {
        return AppVersion::isRelease($this->codeVersion) && $this->databaseVersion !== $this->codeVersion;
    }

    public function pending(): bool
    {
        return ! $this->migrations->finished() || $this->seedersPending();
    }

    /** Older code than the database was last upgraded for (a folder swapped back without a restore). */
    public function codeIsOlder(): bool
    {
        return AppVersion::isRelease($this->codeVersion) && $this->databaseVersion !== null
            && AppVersion::isRelease($this->databaseVersion) && AppVersion::compare($this->codeVersion, $this->databaseVersion) < 0;
    }
}
