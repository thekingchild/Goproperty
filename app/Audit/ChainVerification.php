<?php

declare(strict_types=1);

namespace App\Audit;

/** The result of walking the audit chain. */
final readonly class ChainVerification
{
    private function __construct(
        public bool $intact,
        public int $checked,
        public ?int $brokenAtId,
        public ?string $reason,
    ) {}

    public static function intact(int $checked): self
    {
        return new self(true, $checked, null, null);
    }

    public static function broken(int $checked, ?int $id, string $reason): self
    {
        return new self(false, $checked, $id, $reason);
    }
}
