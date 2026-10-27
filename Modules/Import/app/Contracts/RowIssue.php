<?php

declare(strict_types=1);

namespace Modules\Import\Contracts;

/** A problem with one row: the column it is about (a column key, or null for the whole row) and a message for the agency. */
final readonly class RowIssue
{
    public function __construct(
        public IssueSeverity $severity,
        public ?string $column,
        public string $message,
    ) {}

    public static function error(?string $column, string $message): self
    {
        return new self(IssueSeverity::Error, $column, $message);
    }

    public static function warning(?string $column, string $message): self
    {
        return new self(IssueSeverity::Warning, $column, $message);
    }

    public function isError(): bool
    {
        return $this->severity === IssueSeverity::Error;
    }
}
