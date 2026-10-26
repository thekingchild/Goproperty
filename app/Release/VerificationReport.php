<?php

declare(strict_types=1);

namespace App\Release;

final readonly class VerificationReport
{
    /**
     * @param  list<string>  $problems  reasons to refuse the package
     * @param  list<string>  $notes  worth knowing, not a failure
     */
    public function __construct(
        public ?string $version,
        public int $files,
        public array $problems = [],
        public array $notes = [],
    ) {}

    public function passed(): bool
    {
        return $this->problems === [];
    }
}
