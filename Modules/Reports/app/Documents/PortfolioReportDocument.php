<?php

declare(strict_types=1);

namespace Modules\Reports\Documents;

use App\Documents\Document;
use App\Documents\DocumentBranding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Reports\Reports\PortfolioReport;

/**
 * The Reports page as a PDF (P08-01; BRANDING_DOCUMENTS.md § 4 "Portfolio
 * report"): the period, the building (or every building), the headline
 * figures, then collections vs billed by month, arrears aging with the
 * oldest bills, upcoming renewals, occupancy, expenses by category and
 * per-building performance, in the organization's branding and layout as
 * they are now. A working document, not an issued one: rendered on each
 * download, never kept, no number or verification code. Tenants by name
 * only. View `reports::documents.report.page`.
 */
final readonly class PortfolioReportDocument implements Document
{
    /** How many arrears and renewals the PDF lists; the exports hold them all. */
    public const int LISTED = 40;

    public function __construct(
        public PortfolioReport $report,
        public DocumentBranding $brand,
        public CarbonImmutable $preparedAt,
    ) {}

    public function view(): string
    {
        return 'reports::documents.report.page';
    }

    public function data(): array
    {
        return ['document' => $this, 'report' => $this->report, 'listed' => self::LISTED];
    }

    public function brand(): DocumentBranding
    {
        return $this->brand;
    }

    public function fileName(): string
    {
        $building = $this->report->buildingName !== null ? '-'.Str::slug($this->report->buildingName) : '';

        return 'report-'.$this->report->filters->from->toDateString().'-to-'.$this->report->filters->to->toDateString().$building.'.pdf';
    }

    public function footerLine(): string
    {
        return $this->brand->name().' · Report '.$this->report->filters->label();
    }
}
