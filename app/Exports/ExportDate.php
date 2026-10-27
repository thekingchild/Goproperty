<?php

declare(strict_types=1);

namespace App\Exports;

use DateTimeInterface;

/** A calendar date for an export cell (ExportCell::date()): the date as stored, no time zone shift. */
final readonly class ExportDate
{
    public function __construct(private DateTimeInterface $date) {}

    public function format(): string
    {
        return $this->date->format('Y-m-d');
    }
}
