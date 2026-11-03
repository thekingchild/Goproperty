<?php

declare(strict_types=1);

namespace Modules\Reports\Http\Controllers;

use App\Documents\BrandingSnapshots;
use App\Documents\DocumentRenderer;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Reports\Documents\PortfolioReportDocument;
use Modules\Reports\Reports\PortfolioReport;
use Modules\Reports\Reports\PortfolioReports;
use Modules\Reports\Reports\ReportFilters;
use Modules\Reports\Reports\ReportScope;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Reports page as a PDF (P08-01), for the filters in the query string
 * (from, to, building, days; cleaned by ReportFilters as on the page), in
 * the organization's branding now, rendered through Core's
 * DocumentRenderer on each download and never kept. ReportPolicy::export
 * (`reports.view`); a building outside the member's narrowing answers 404.
 */
final class ReportPdfController
{
    public function __invoke(Request $request, Organization $organization, PortfolioReports $reports, BuildingAccess $access, BrandingSnapshots $brandings, DocumentRenderer $renderer): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        Gate::authorize('export', PortfolioReport::class);

        $filters = ReportFilters::fromInput($request->only(['from', 'to', 'building', 'days']));
        abort_unless(ReportScope::allows($user, $filters, $access), 404);

        $document = new PortfolioReportDocument($reports->all($user, $filters), $brandings->take($organization)->documentBranding(), CarbonImmutable::now());

        return response($renderer->pdf($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document->fileName().'"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
