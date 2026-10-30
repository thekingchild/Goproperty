<?php

declare(strict_types=1);

namespace App\Platform\Deliveries\Health;

use App\Mail\Suppression\EmailWebhookDelivery;
use App\Mail\Suppression\SuppressionReason;
use App\Models\EmailSuppression;
use App\Platform\Deliveries\DeliveryLogEntry;
use App\Platform\Staff\StaffPermission;
use App\Sms\Credits\CreditReason;
use App\Sms\SmsSender;
use App\Sms\SmsText;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The figures of the console's delivery health dashboard (P05-05;
 * PLATFORM_CONSOLE.md § 2 "Messaging"), read across organizations from
 * the delivery log and the SMS credit ledger. Console read model only
 * (App\Platform may read every organization; ARCHITECTURE.md § 4): it
 * never uses the organization-scoped models and never changes anything.
 * Never reads a message's text; numbers in errors are masked.
 *
 * Every query is plain grouping and CASE sums, the same on MySQL 8 and
 * MariaDB 10.6+.
 */
final class MessagingHealth
{
    /** The channels always shown, even with nothing sent. */
    public const array CHANNELS = ['sms', 'whatsapp'];

    /** Who sees the Messaging part: the operations dashboard's staff and whoever reads the delivery log. */
    public static function visibleTo(?Authenticatable $staff): bool
    {
        return $staff !== null && Gate::forUser($staff)->any([StaffPermission::OperationsView->value, StaffPermission::DeliveryLogsView->value]);
    }

    /** @return array<string, ChannelFigures> by channel: SMS, WhatsApp, then any other */
    public function channels(DeliveryWindow $window, CarbonInterface $now): array
    {
        $counts = array_fill_keys(self::CHANNELS, []);

        DeliveryLogEntry::query()->toBase()
            ->where('queued_at', '>=', $window->since($now))
            ->groupBy('channel', 'status')
            ->select(['channel', 'status'])
            ->selectRaw('COUNT(*) AS deliveries')
            ->get()
            ->each(function (object $row) use (&$counts): void {
                $counts[(string) $row->channel][(string) $row->status] = (int) $row->deliveries;
            });

        $figures = [];

        foreach ($counts as $channel => $byStatus) {
            $figures[$channel] = new ChannelFigures($channel, $window, $byStatus);
        }

        return $figures;
    }

    public function smsSpend(DeliveryWindow $window, CarbonInterface $now): SmsSpend
    {
        $since = $window->since($now);
        $sent = DeliveryOutcome::Sent->statusValues();

        $deliveries = DeliveryLogEntry::query()->toBase()
            ->where('channel', 'sms')
            ->where('queued_at', '>=', $since)
            ->whereIn('status', $sent)
            ->selectRaw('COUNT(*) AS messages')
            ->selectRaw('SUM(COALESCE(pages, 0)) AS pages')
            ->selectRaw('SUM(CASE WHEN pages IS NULL THEN 1 ELSE 0 END) AS pages_unreported')
            ->selectRaw('SUM(COALESCE(cost_kobo, 0)) AS cost_kobo')
            ->selectRaw('SUM(CASE WHEN cost_kobo IS NULL THEN 1 ELSE 0 END) AS cost_unreported')
            ->first();

        // Credits given back are adjustments linked to the SMS GoText refused
        // (P05-03 default 38); staff adjustments carry no delivery.
        $credits = DB::table('sms_credit_entries')
            ->where('created_at', '>=', $since)
            ->selectRaw('SUM(CASE WHEN reason = ? THEN -credits ELSE 0 END) AS taken', [CreditReason::Usage->value])
            ->selectRaw('SUM(CASE WHEN reason = ? AND notification_delivery_id IS NOT NULL AND credits > 0 THEN credits ELSE 0 END) AS given_back', [CreditReason::Adjustment->value])
            ->selectRaw('SUM(CASE WHEN reason = ? THEN credits ELSE 0 END) AS bought', [CreditReason::Purchase->value])
            ->selectRaw('SUM(CASE WHEN reason = ? THEN credits ELSE 0 END) AS allowance', [CreditReason::MonthlyAllowance->value])
            ->first();

        return new SmsSpend(
            window: $window,
            messages: self::int($deliveries?->messages),
            pages: self::int($deliveries?->pages),
            pagesUnreported: self::int($deliveries?->pages_unreported),
            costKobo: self::int($deliveries?->cost_kobo),
            costUnreported: self::int($deliveries?->cost_unreported),
            creditsTaken: self::int($credits?->taken),
            creditsGivenBack: self::int($credits?->given_back),
            creditsBought: self::int($credits?->bought),
            allowanceGranted: self::int($credits?->allowance),
        );
    }

    /**
     * Every provider that handled a message queued in the last 7 days, and
     * the SMS provider in use even when it handled none.
     *
     * @return list<ProviderStatus>
     */
    public function providers(CarbonInterface $now): array
    {
        $week = DeliveryWindow::Week->since($now);
        $day = DeliveryWindow::Day->since($now);
        $attempted = DeliveryOutcome::Attempted->statusValues();
        $problems = DeliveryOutcome::Problems->statusValues();
        $in = static fn (array $values): string => implode(', ', array_fill(0, count($values), '?'));

        $groups = DeliveryLogEntry::query()->toBase()
            ->where('queued_at', '>=', $week)
            ->groupBy('channel', 'provider')
            ->select(['channel', 'provider'])
            ->selectRaw('MAX(sent_at) AS last_sent_at')
            ->selectRaw('MAX(status_checked_at) AS last_status_check_at')
            ->selectRaw('SUM(CASE WHEN queued_at >= ? AND status IN ('.$in($attempted).') THEN 1 ELSE 0 END) AS attempted', [$day, ...$attempted])
            ->selectRaw('SUM(CASE WHEN queued_at >= ? AND status IN ('.$in($problems).') THEN 1 ELSE 0 END) AS problems', [$day, ...$problems])
            ->get();

        $providers = [];

        foreach ($groups as $group) {
            $channel = (string) $group->channel;
            $provider = (string) $group->provider;
            $failure = DeliveryLogEntry::query()->toBase()
                ->where('queued_at', '>=', $week)
                ->where('channel', $channel)
                ->where('provider', $provider)
                ->whereIn('status', $problems)
                ->orderByDesc('failed_at')
                ->orderByDesc('id')
                ->first(['failed_at', 'error']);

            $providers[$channel.':'.$provider] = new ProviderStatus(
                channel: $channel,
                provider: $provider,
                attempted: self::int($group->attempted),
                problems: self::int($group->problems),
                lastSentAt: self::time($group->last_sent_at),
                lastFailureAt: self::time($failure?->failed_at),
                lastFailureError: $failure !== null ? self::maskNumbers(is_string($failure->error) ? $failure->error : null) : null,
                lastStatusCheckAt: self::time($group->last_status_check_at),
            );
        }

        $sms = app(SmsSender::class)->name();
        $providers['sms:'.$sms] ??= new ProviderStatus('sms', $sms, 0, 0, null, null, null, null);

        uasort($providers, static fn (ProviderStatus $a, ProviderStatus $b): int => [self::channelOrder($a->channel), $a->provider] <=> [self::channelOrder($b->channel), $b->provider]);

        return array_values($providers);
    }

    /**
     * Agencies' own WhatsApp connections (P05-06: each organization sends
     * through its own account), by status; disconnected ones are left out.
     *
     * @return array{active: int, failing: int, pending_test: int}
     */
    public function whatsAppConnections(): array
    {
        $counts = DB::table('organization_whatsapp_connections')
            ->where('status', '!=', 'disconnected')
            ->groupBy('status')
            ->select('status')
            ->selectRaw('COUNT(*) AS connections')
            ->pluck('connections', 'status');

        return [
            'active' => self::int($counts->get('active')),
            'failing' => self::int($counts->get('failing')),
            'pending_test' => self::int($counts->get('pending_test')),
        ];
    }

    public function email(DeliveryWindow $window, CarbonInterface $now): EmailFigures
    {
        $since = $window->since($now);
        $suppressed = EmailSuppression::query()->toBase()
            ->where('suppressed_at', '>=', $since)
            ->groupBy('reason')
            ->select('reason')
            ->selectRaw('COUNT(*) AS addresses')
            ->pluck('addresses', 'reason');

        return new EmailFigures(
            window: $window,
            bounced: self::int($suppressed->get(SuppressionReason::Bounce->value)),
            complaints: self::int($suppressed->get(SuppressionReason::Complaint->value)),
            webhookCalls: EmailWebhookDelivery::query()->where('received_at', '>=', $since)->count(),
        );
    }

    /** When the email provider's bounce and complaint webhook last called. */
    public function lastEmailWebhookAt(): ?CarbonImmutable
    {
        return self::time(EmailWebhookDelivery::query()->max('received_at'));
    }

    /** An error as the console may show it: numbers masked to their first and last four digits. */
    public static function maskNumbers(?string $error): ?string
    {
        if ($error === null || $error === '') {
            return null;
        }

        $masked = preg_replace_callback('/\+?\d[\d \-().]{5,}\d/', static function (array $match): string {
            $digits = (string) preg_replace('/\D/', '', $match[0]);

            return strlen($digits) >= 7 ? SmsText::mask(str_starts_with($match[0], '+') ? '+'.$digits : $digits) : $match[0];
        }, $error);

        return mb_strimwidth($masked ?? '', 0, 200, '…');
    }

    private static function channelOrder(string $channel): int
    {
        $position = array_search($channel, self::CHANNELS, true);

        return $position === false ? count(self::CHANNELS) : $position;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, (string) config('app.timezone')) : null;
    }
}
