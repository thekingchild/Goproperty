<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

/** One month of the collections vs billed report (P08-01). */
final readonly class CollectionsMonth
{
    public function __construct(
        public ReportMonth $month,
        public FlowTotals $totals,
    ) {}
}
