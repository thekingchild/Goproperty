<?php

declare(strict_types=1);

namespace App\Installer\Database;

final readonly class ProbeResult
{
    /**
     * @param  list<string>  $tables  tables already in the chosen database
     */
    public function __construct(
        public bool $ok,
        public string $message,
        public ?string $serverVersion = null,
        public bool $mariaDb = false,
        public array $tables = [],
    ) {}
}
