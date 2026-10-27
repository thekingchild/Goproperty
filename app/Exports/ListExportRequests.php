<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporting a workspace list, in two steps (AGENT.md § 5):
 *
 * 1. start(), from the page's Export button (Livewire, WithListExports):
 *    checks the list's policy with the page's current filters, the rate
 *    limit (PER_HOUR exports per member) and the size (MAX_ROWS), then
 *    hands out a one-time ticket, valid TICKET_SECONDS, for this member
 *    in this organization. The filters stay on the server with the ticket
 *    (a search may be a phone number: not in a URL).
 * 2. download(), the GET /o/{organization}/exports/{ticket} the page then
 *    opens: takes the ticket (once), checks the policy again, writes the
 *    audit entry with the row count, and streams the file
 *    (ListExportWriter). Livewire would hold a whole download in memory,
 *    so the file comes from a plain request.
 *
 * Both run inside the organization's context, so the list's query sees
 * only the organization's rows and the member's narrowing. Exports are
 * reads: open in a suspended organization and to read-only support
 * sessions, except lists of people's contact details, whose `export…`
 * ability is #[BlockedWhileImpersonating] (PLATFORM_CONSOLE.md § 6).
 */
final readonly class ListExportRequests
{
    public const int PER_HOUR = 30;

    public const int MAX_ROWS = 50_000;

    public const int TICKET_SECONDS = 300;

    public function __construct(
        private ListExports $exports,
        private OrganizationContext $context,
        private ExportAudit $audit,
        private ListExportWriter $writer,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  the page's current filters
     * @return string the download URL
     *
     * @throws ListExportRefused
     */
    public function start(User $user, string $list, string $format, array $filters): string
    {
        $export = $this->exports->find($list);
        $exportFormat = ExportFormat::tryFrom($format);
        abort_if($export === null || $exportFormat === null, 404);

        $organization = $this->context->get();
        $filters = $export->filters($filters);
        $export->authorize($user, $filters);

        $key = 'list-exports:'.$organization->id.':'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            throw new ListExportRefused(__('You have exported a lot in the last hour. Try again in :minutes minutes.', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))]));
        }

        $rows = $export->count($user, $filters);

        if ($rows > self::MAX_ROWS) {
            throw new ListExportRefused(__('This list has :rows rows; one export holds at most :max. Narrow it down with the search or filters and export each part.', [
                'rows' => number_format($rows),
                'max' => number_format(self::MAX_ROWS),
            ]));
        }

        RateLimiter::hit($key, 3600);
        $ticket = Str::random(40);

        Cache::put(self::cacheKey($ticket), [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'list' => $export->key(),
            'format' => $exportFormat->value,
            'filters' => $filters,
        ], self::TICKET_SECONDS);

        return route('workspace.exports.download', ['organization' => $organization, 'ticket' => $ticket]);
    }

    /** The file, streamed; 404 for a ticket that is unknown, used, expired, or another member's or organization's. */
    public function download(User $user, string $ticket): StreamedResponse
    {
        $organization = $this->context->get();
        $request = Cache::get(self::cacheKey($ticket));

        // Another member's (or organization's) attempt leaves the ticket alone;
        // the member's own use takes it, once.
        abort_unless(is_array($request)
            && ($request['organization_id'] ?? null) === $organization->id
            && ($request['user_id'] ?? null) === $user->id
            && Cache::pull(self::cacheKey($ticket)) !== null, 404);

        $export = $this->exports->find((string) ($request['list'] ?? ''));
        $format = ExportFormat::tryFrom((string) ($request['format'] ?? ''));
        abort_if($export === null || $format === null, 404);

        // The member may have lost the list since pressing Export.
        $filters = $export->filters(is_array($request['filters'] ?? null) ? $request['filters'] : []);
        $export->authorize($user, $filters);

        $this->audit->record($organization, $user, $export, $format, $filters, $export->count($user, $filters));

        return $this->writer->response($export, $user, $filters, $format, self::fileName($organization, $export, $format));
    }

    /** "acme-homes-owners-2026-10-27-1405.csv" (Lagos time). */
    public static function fileName(Organization $organization, ListExport $export, ExportFormat $format): string
    {
        $stamp = CarbonImmutable::now(ExportCell::TIMEZONE)->format('Y-m-d-Hi');

        return Str::slug($organization->slug.'-'.str_replace('_', '-', $export->key())).'-'.$stamp.'.'.$format->value;
    }

    private static function cacheKey(string $ticket): string
    {
        return 'list-export:'.hash('sha256', $ticket);
    }
}
