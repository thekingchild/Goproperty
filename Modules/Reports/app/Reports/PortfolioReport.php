<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

/** The six customer dashboards for one member and one set of filters (P08-01). */
final readonly class PortfolioReport
{
    public function __construct(
        public ReportFilters $filters,
        public ReportScope $scope,
        public ?string $buildingName,
        public OccupancyReport $occupancy,
        public CollectionsReport $collections,
        public ArrearsReport $arrears,
        public RenewalsReport $renewals,
        public ExpensesReport $expenses,
        public BuildingsReport $buildings,
    ) {}
}
