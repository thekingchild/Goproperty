<?php

declare(strict_types=1);

namespace App\Banking;

/** One account-name lookup: the outcome and the name the bank returned, if any. */
final readonly class NameCheckResult
{
    public function __construct(
        public NameCheckOutcome $outcome,
        public ?string $bankAccountName = null,
        public ?string $reason = null,
    ) {}

    public static function matched(string $bankAccountName): self
    {
        return new self(NameCheckOutcome::Matched, $bankAccountName);
    }

    public static function mismatched(string $bankAccountName): self
    {
        return new self(NameCheckOutcome::Mismatched, $bankAccountName);
    }

    public static function notFound(): self
    {
        return new self(NameCheckOutcome::NotFound);
    }

    public static function unavailable(string $reason): self
    {
        return new self(NameCheckOutcome::Unavailable, null, $reason);
    }
}
