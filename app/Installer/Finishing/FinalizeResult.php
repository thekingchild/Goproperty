<?php

declare(strict_types=1);

namespace App\Installer\Finishing;

final readonly class FinalizeResult
{
    /**
     * @param  string  $adminPath  shown once on the finish screen; never logged
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $adminPath,
        public array $warnings = [],
    ) {}
}
