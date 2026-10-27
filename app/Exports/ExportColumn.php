<?php

declare(strict_types=1);

namespace App\Exports;

use Closure;

/**
 * One column of a list export: the header people read, and the value of a
 * row. The value may be anything ExportCell understands (text, whole
 * numbers, App\Support\Money, dates, enums with a label(), true/false,
 * null); ExportCell turns it into a safe cell. Never return an encrypted
 * field (ID numbers, bank numbers) or a secret: a masked value at most.
 */
final readonly class ExportColumn
{
    /** @param  Closure(mixed): mixed  $value */
    public function __construct(
        public string $header,
        public Closure $value,
    ) {}

    public static function make(string $header, Closure $value): self
    {
        return new self($header, $value);
    }
}
