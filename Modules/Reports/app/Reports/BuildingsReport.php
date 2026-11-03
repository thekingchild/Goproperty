<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

/**
 * Per-building performance (P08-01): each active building, and any other
 * building with money in the range (archived, or moved away by a
 * handover), by name, then the organization's own money linked to no
 * building when there is any.
 */
final readonly class BuildingsReport
{
    /** @param  list<BuildingLine>  $lines */
    public function __construct(public array $lines) {}
}
