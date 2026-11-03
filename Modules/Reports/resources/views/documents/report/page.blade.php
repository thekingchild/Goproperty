{{--
    The Reports page as a PDF (P08-01; BRANDING_DOCUMENTS.md § 4 "Portfolio
    report", § 5), in every layout (the kit's page and styles carry the
    layout): letterhead with REPORT, the period and the building, the
    headline figures, then each report. $document: Modules\Reports\Documents\PortfolioReportDocument;
    $report: its PortfolioReport; $listed: how many arrears and renewals it
    lists. Table layouts and simple CSS only.
--}}
@extends('documents.partials.page', ['docTitle' => 'Report '.$report->filters->label(), 'footerLine' => $document->footerLine(), 'void' => false])

@section('document-styles')
    @include('reports::documents.report.styles')
@endsection

@section('content')
    @include('documents.partials.letterhead', [
        'brand' => $brand,
        'title' => 'REPORT',
        'titleClass' => 'doc-title-report',
        'meta' => [
            'Period' => e($report->filters->label()),
            'Buildings' => e($report->buildingName ?? 'All buildings'),
            'Prepared' => e($document->preparedAt->setTimezone('Africa/Lagos')->format('d/m/Y H:i')),
        ],
    ])
    <div class="rule"></div>
    @include('reports::documents.report.summary')
    @include('reports::documents.report.collections')
    @include('reports::documents.report.arrears')
    @include('reports::documents.report.renewals')
    @include('reports::documents.report.occupancy')
    @include('reports::documents.report.expenses')
    @include('reports::documents.report.buildings')
@endsection
