<?php

declare(strict_types=1);

namespace App\Installer\Migrations;

final readonly class MigrationProgress
{
    /**
     * @param  list<string>  $pending  migration names still to run, in order
     */
    public function __construct(
        public int $total,
        public int $done,
        public array $pending,
    ) {}

    public function finished(): bool
    {
        return $this->pending === [];
    }

    /** "34 of 112" */
    public function label(): string
    {
        return "{$this->done} of {$this->total}";
    }
}
