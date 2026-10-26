<?php

declare(strict_types=1);

namespace App\Installer\Migrations;

final readonly class BatchResult
{
    /**
     * @param  list<string>  $ran  migrations this batch completed
     */
    public function __construct(
        public array $ran,
        public MigrationProgress $progress,
        public ?string $failedMigration = null,
        public ?string $error = null,
    ) {}

    public function failed(): bool
    {
        return $this->failedMigration !== null;
    }
}
