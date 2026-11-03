<?php

declare(strict_types=1);

namespace Modules\Reports\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Reports\Enums\AgingBucket;
use Modules\Reports\Enums\ReportPreset;
use Modules\Reports\Reports\PortfolioReport;
use Modules\Reports\Reports\PortfolioReports;
use Modules\Reports\Reports\ReportFilters;
use Modules\Reports\Reports\ReportScope;

/**
 * Reports, /o/{organization}/reports (P08-01): the customer dashboards —
 * occupancy, collections vs billed, arrears aging, upcoming renewals,
 * expenses by category and per-building performance — for a date range
 * (a preset or two dates, Lagos days) and one building or all, the filters
 * kept in the URL. Each report exports to Excel and CSV (the list export,
 * Modules\Reports\Lists) and the whole page to PDF (ReportPdfController).
 *
 * Members with `reports.view` see it (ReportPolicy). Reads only. Runs
 * inside the `organization` middleware, replayed for Livewire updates.
 */
#[Layout('layouts::app')]
#[Title('Reports')]
final class ReportsDashboard extends Component
{
    use WithListExports;

    /** How many arrears and renewals the page lists; the exports hold them all. */
    public const int LISTED = 20;

    #[Locked]
    public int $organizationId;

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $building = '';

    #[Url(except: '')]
    public string $days = '';

    /** The preset the dates match (ReportPreset), or `custom`. */
    public string $period = '';

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', PortfolioReport::class);
        $this->clean();
    }

    public function updatedPeriod(): void
    {
        $preset = ReportPreset::tryFrom($this->period);
        $range = $preset?->range(ReportFilters::today());

        if ($range !== null) {
            [$from, $to] = $range;
            $this->from = $from->toDateString();
            $this->to = $to->toDateString();
        }

        $this->clean();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', 'building', 'days'], true)) {
            $this->clean();
        }
    }

    public function render(OrganizationContext $context, PortfolioReports $reports, PropertyDirectory $buildings): View
    {
        $this->authorize('viewAny', PortfolioReport::class);
        $user = $this->user();
        $filters = $this->filters();

        return view('reports::livewire.reports-dashboard', [
            'organization' => $context->get(),
            'report' => $reports->all($user, $filters),
            'filters' => $filters,
            'presets' => ReportPreset::cases(),
            'windows' => ReportFilters::RENEWAL_WINDOWS,
            'buildings' => $buildings->activeOptions($user),
            'buckets' => AgingBucket::cases(),
            'pdfUrl' => route('workspace.reports.pdf', [$context->get(), ...$filters->toArray()]),
            'listed' => self::LISTED,
        ]);
    }

    /** The page's reports, with their filters (Modules\Reports\Lists; the Export buttons). */
    protected function listExports(): array
    {
        $filters = $this->filters()->toArray();

        return array_fill_keys(['report_occupancy', 'report_collections', 'report_arrears', 'report_renewals', 'report_expenses', 'report_buildings'], $filters);
    }

    private function filters(): ReportFilters
    {
        return ReportFilters::fromInput(['from' => $this->from, 'to' => $this->to, 'building' => $this->building, 'days' => $this->days]);
    }

    /** Puts the cleaned filters back on the page (and in the URL), so what it shows is what it says. */
    private function clean(): void
    {
        $filters = $this->filters();

        if (! ReportScope::allows($this->user(), $filters, app(BuildingAccess::class)) || ($filters->buildingId !== null && ! app(PropertyDirectory::class)->isActive($filters->buildingId))) {
            $filters = ReportFilters::fromInput([...$filters->toArray(), 'building' => null]);
        }

        $this->from = $filters->from->toDateString();
        $this->to = $filters->to->toDateString();
        $this->building = $filters->buildingId === null ? '' : (string) $filters->buildingId;
        $this->days = $filters->renewalDays === ReportFilters::DEFAULT_RENEWAL_DAYS ? '' : (string) $filters->renewalDays;
        $this->period = $filters->preset()->value;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
