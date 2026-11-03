<?php

declare(strict_types=1);

namespace App\Platform\Filament\Pages;

use App\Platform\Analytics\AnalyticsRange;
use App\Platform\Analytics\PlatformAnalytics;
use App\Platform\Staff\StaffPermission;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Console → Operations → System analytics: the System Analytics Dashboard
 * of PLATFORM_CONSOLE.md § 3 (P08-02), for `analytics.view` (analyst,
 * operations-manager, super-admin). Business numbers — revenue, customers,
 * usage, Transaction Tiers, collections recorded by agencies and a
 * customer leaderboard — read only from the nightly `daily_platform_metrics`
 * (PlatformAnalytics), never from live customer tables. One date range for
 * every chart, kept in the URL; each section downloads as CSV for that
 * range (AnalyticsExportController). Aggregated figures and customers'
 * business names only; no personal data.
 */
final class SystemAnalytics extends Page
{
    protected static ?string $slug = 'analytics';

    protected static ?string $title = 'System analytics';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 0;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected string $view = 'console.analytics.page';

    #[Url(as: 'range')]
    public string $preset = AnalyticsRange::DEFAULT;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public static function canAccess(): bool
    {
        return PlatformAnalytics::visibleTo(Filament::auth()->user());
    }

    public function mount(): void
    {
        $this->clean();
    }

    public function updated(string $property): void
    {
        if ($property === 'preset' && $this->preset !== 'custom') {
            $this->from = $this->to = '';
        } elseif (in_array($property, ['from', 'to'], true)) {
            $this->preset = 'custom';
        }

        $this->clean();
    }

    public function getSubheading(): string
    {
        return 'From the nightly summary of every organization, taken just after each Lagos day ends. Money in naira, before VAT unless marked.';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $range = $this->range();

        return [
            'analytics' => new PlatformAnalytics($range),
            'range' => $range,
            'canOpenCustomers' => Gate::forUser(Filament::auth()->user())->allows(StaffPermission::CustomersView->value),
        ];
    }

    private function range(): AnalyticsRange
    {
        return AnalyticsRange::from($this->preset, $this->from, $this->to);
    }

    /** The filters as the range reads them, so the URL never keeps nonsense. */
    private function clean(): void
    {
        $range = $this->range();
        $this->preset = $range->preset;
        $this->from = $range->from->toDateString();
        $this->to = $range->to->toDateString();
    }
}
