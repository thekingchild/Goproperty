<?php

declare(strict_types=1);

namespace App\Installer\Upgrades;

/** What one request of the upgrade step did (INSTALLER.md § 10). */
final readonly class UpgradeStep
{
    /**
     * @param  list<string>  $ran  migrations this request completed
     */
    public function __construct(
        public UpgradeStepKind $kind,
        public UpgradeState $state,
        public array $ran = [],
        public ?string $failedMigration = null,
        public ?string $error = null,
        public bool $maintenanceStarted = false,
        public bool $maintenanceEnded = false,
    ) {}

    public function failed(): bool
    {
        return $this->kind === UpgradeStepKind::MigrationFailed || $this->kind === UpgradeStepKind::FinishFailed;
    }
}
