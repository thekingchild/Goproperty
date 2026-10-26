<?php

declare(strict_types=1);

namespace App\Installer;

final readonly class CheckpointState
{
    private function __construct(
        public bool $exists,
        public bool $corrupt,
        public ?InstallStage $stage,
        public ?string $fingerprint,
    ) {}

    public static function missing(): self
    {
        return new self(false, false, null, null);
    }

    public static function corrupt(): self
    {
        return new self(true, true, null, null);
    }

    public static function valid(InstallStage $stage, string $fingerprint): self
    {
        return new self(true, false, $stage, $fingerprint);
    }

    /** Fail closed: an unreadable checkpoint keeps the installer shut. */
    public function saysFinished(): bool
    {
        return $this->corrupt || ($this->stage?->isFinished() ?? false);
    }
}
