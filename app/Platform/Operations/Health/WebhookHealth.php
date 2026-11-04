<?php

declare(strict_types=1);

namespace App\Platform\Operations\Health;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Paystack webhook health (PLATFORM_CONSOLE.md § 2 "Webhooks"), from
 * `webhook_log`: GoProperty's own account (`/webhooks/paystack`,
 * organization null) and each agency's own connected account
 * (`/webhooks/paystack/{token}`). Console read model across organizations
 * (ARCHITECTURE.md § 4): direct reads, plain sums, never a delivery's body.
 */
final class WebhookHealth
{
    public function own(CarbonInterface $since): WebhookFigures
    {
        $row = $this->figures(DB::table('webhook_log')->whereNull('organization_id')->where('provider', 'paystack'), $since)->first();

        return new WebhookFigures(
            organizationId: null,
            name: 'The platform\'s own account',
            received: (int) ($row->received ?? 0),
            failed: (int) ($row->failed ?? 0),
            awaiting: (int) ($row->awaiting ?? 0),
            lastReceivedAt: self::time(DB::table('webhook_log')->whereNull('organization_id')->where('provider', 'paystack')->max('received_at')),
        );
    }

    /**
     * Agencies' connected accounts (not disconnected) and any agency that
     * sent deliveries in the period: failures first, then those waiting,
     * then the most recent.
     *
     * @return list<WebhookFigures>
     */
    public function agencies(CarbonInterface $since, int $limit = 20): array
    {
        $stats = $this->figures(DB::table('webhook_log')->whereNotNull('organization_id')->where('provider', 'paystack'), $since)
            ->addSelect('organization_id')
            ->groupBy('organization_id')
            ->get()
            ->keyBy('organization_id');

        $connections = DB::table('organization_paystack_connections')
            ->where('status', '!=', 'disconnected')
            ->pluck('status', 'organization_id');

        $ids = $stats->keys()->merge($connections->keys())->map(intval(...))->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $names = DB::table('organizations')->whereIn('id', $ids)->pluck('name', 'id');
        $last = DB::table('webhook_log')->whereIn('organization_id', $ids)->where('provider', 'paystack')
            ->groupBy('organization_id')->select('organization_id')->selectRaw('MAX(received_at) AS last_received_at')
            ->pluck('last_received_at', 'organization_id');

        $agencies = $ids->map(fn (int $id): WebhookFigures => new WebhookFigures(
            organizationId: $id,
            name: (string) ($names->get($id) ?? 'Organization #'.$id),
            received: (int) ($stats->get($id)->received ?? 0),
            failed: (int) ($stats->get($id)->failed ?? 0),
            awaiting: (int) ($stats->get($id)->awaiting ?? 0),
            lastReceivedAt: self::time($last->get($id)),
            connectionStatus: is_string($status = $connections->get($id)) ? $status : 'disconnected',
        ))->all();

        usort($agencies, static fn (WebhookFigures $a, WebhookFigures $b): int => [$b->failed, $b->awaiting, $b->lastReceivedAt?->getTimestamp() ?? 0, $a->name]
            <=> [$a->failed, $a->awaiting, $a->lastReceivedAt?->getTimestamp() ?? 0, $b->name]);

        return array_slice($agencies, 0, $limit);
    }

    /** How many agencies have a Paystack account connected (not disconnected). */
    public function connectedAgencies(): int
    {
        return DB::table('organization_paystack_connections')->where('status', '!=', 'disconnected')->count();
    }

    private function figures(Builder $query, CarbonInterface $since): Builder
    {
        return $query
            ->where('received_at', '>=', $since)
            ->selectRaw('COUNT(*) AS received')
            ->selectRaw('SUM(CASE WHEN signature_valid = 0 OR processing_error IS NOT NULL THEN 1 ELSE 0 END) AS failed')
            ->selectRaw('SUM(CASE WHEN signature_valid = 1 AND processed_at IS NULL AND processing_error IS NULL THEN 1 ELSE 0 END) AS awaiting');
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
