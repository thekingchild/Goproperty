<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

use Carbon\CarbonImmutable;

/** One Lagos month of a report's range, clipped to the range (ReportFilters::months). */
final readonly class ReportMonth
{
    public function __construct(
        public string $key,
        public string $label,
        public CarbonImmutable $first,
        public CarbonImmutable $last,
    ) {}

    /** Whether the moment falls in this month's part of the range (Lagos days). */
    public function contains(CarbonImmutable $at): bool
    {
        $day = $at->setTimezone(ReportFilters::TIMEZONE)->format('Y-m-d');

        return $day >= $this->first->format('Y-m-d') && $day <= $this->last->format('Y-m-d');
    }
}
