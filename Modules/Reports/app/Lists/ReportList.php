<?php

declare(strict_types=1);

namespace Modules\Reports\Lists;

use App\Exports\ListExport;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Reports\Reports\PortfolioReport;
use Modules\Reports\Reports\PortfolioReports;
use Modules\Reports\Reports\ReportFilters;
use Modules\Reports\Reports\ReportScope;

/**
 * One report of the Reports page as a list export (P08-01: "CSV + PDF
 * export"; P02-07's list export gives CSV and Excel): the rows the page
 * shows for the same cleaned filters (ReportFilters), worked out by the
 * same builder (PortfolioReports). A building the member's narrowing does
 * not let them see is refused (404), as on the page.
 */
abstract class ReportList implements ListExport
{
    public function __construct(
        protected readonly PortfolioReports $reports,
        private readonly BuildingAccess $access,
    ) {}

    public function filters(array $input): array
    {
        return ReportFilters::fromInput($input)->toArray();
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', PortfolioReport::class);
        abort_unless(ReportScope::allows($user, ReportFilters::fromInput($filters), $this->access), 404);
    }

    public function count(User $user, array $filters): int
    {
        $rows = $this->rows($user, $filters);

        return is_countable($rows) ? count($rows) : iterator_count($rows);
    }

    /**
     * @param  array<string, string|int|bool>  $filters  cleaned
     * @return array{ReportScope, ReportFilters}
     */
    protected function scoped(User $user, array $filters): array
    {
        $cleaned = ReportFilters::fromInput($filters);

        return [$this->reports->scope($user, $cleaned), $cleaned];
    }
}
