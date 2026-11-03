<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Models\PlatformStaff;
use App\Platform\Analytics\AnalyticsCsv;
use App\Platform\Analytics\AnalyticsRange;
use App\Platform\Analytics\PlatformAnalytics;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads one section of the System analytics page as CSV (P08-02;
 * PLATFORM_CONSOLE.md § 3), for the range in the query string, cleaned as
 * the page cleans it. A console route behind the staff guard and 2FA, for
 * `analytics.view`; aggregated figures and customers' business names only.
 * Every download is one audit entry (log `analytics`).
 */
final class AnalyticsExportController
{
    public function __invoke(Request $request, string $section): StreamedResponse
    {
        $staff = Filament::auth()->user();
        abort_unless($staff instanceof PlatformStaff && PlatformAnalytics::visibleTo($staff), 403);

        $range = AnalyticsRange::from($request->string('range')->toString(), $request->string('from')->toString(), $request->string('to')->toString());
        $csv = AnalyticsCsv::section($section, new PlatformAnalytics($range));

        activity(PlatformAnalytics::AUDIT_LOG)
            ->event('analytics.exported')
            ->causedBy($staff)
            ->withProperties(['section' => $section, 'from' => $range->from->toDateString(), 'to' => $range->to->toDateString(), 'rows' => count($csv['rows'])])
            ->log('Downloaded the '.AnalyticsCsv::SECTIONS[$section].' figures of System analytics');

        $name = sprintf('goproperty-analytics-%s-%s-to-%s.csv', $section, $range->from->toDateString(), $range->to->toDateString());

        return response()->streamDownload(static function () use ($csv): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                throw new RuntimeException('The export could not be written.');
            }

            try {
                fwrite($output, "\u{FEFF}");
                fputcsv($output, $csv['headers'], ',', '"', '', "\r\n");

                foreach ($csv['rows'] as $row) {
                    fputcsv($output, array_map(static fn (string|int|null $cell): string => (string) $cell, $row), ',', '"', '', "\r\n");
                }
            } finally {
                fclose($output);
            }
        }, $name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
