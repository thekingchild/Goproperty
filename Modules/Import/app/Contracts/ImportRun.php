<?php

declare(strict_types=1);

namespace Modules\Import\Contracts;

/**
 * One pass over a workbook: a dry run (nothing saved, no batch id yet
 * needed) or a commit (batchId() is the batch every created record links
 * to). Targets use it to remember the keys of rows that would be created
 * during a dry run, so a later row of the same sheet can be flagged as a
 * duplicate and a later sheet can refer to a record the file creates
 * (a property's owner from the Owners sheet, DATA_IMPORT.md § 1). During a
 * commit nothing is remembered: earlier sheets are already in the database.
 */
final class ImportRun
{
    /** @var array<string, array<string, int>> target => normalised key => first row */
    private array $remembered = [];

    public function __construct(
        public readonly int $organizationId,
        public readonly bool $dryRun,
        private readonly ?int $batchId = null,
    ) {}

    public static function dryRun(int $organizationId, ?int $batchId = null): self
    {
        return new self($organizationId, true, $batchId);
    }

    public static function commit(int $organizationId, int $batchId): self
    {
        return new self($organizationId, false, $batchId);
    }

    /** The batch the records link to (set during a commit). */
    public function batchId(): int
    {
        return $this->batchId ?? throw new \LogicException('A dry run has no batch to link records to.');
    }

    /** During a dry run: this file would create a `$target` record known by `$key`, first at `$row`. */
    public function remember(string $target, string $key, int $row): void
    {
        if (! $this->dryRun) {
            return;
        }

        $this->remembered[$target][self::normalise($key)] ??= $row;
    }

    /** The row of this file that would create the `$target` record known by `$key`, during a dry run. */
    public function rowOf(string $target, string $key): ?int
    {
        return $this->remembered[$target][self::normalise($key)] ?? null;
    }

    public function knows(string $target, string $key): bool
    {
        return $this->rowOf($target, $key) !== null;
    }

    private static function normalise(string $key): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $key)));
    }
}
