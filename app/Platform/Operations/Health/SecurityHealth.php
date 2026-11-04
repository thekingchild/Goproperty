<?php

declare(strict_types=1);

namespace App\Platform\Operations\Health;

use App\Audit\AuthenticationLog;
use App\Models\ImpersonationSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Security for the operations dashboard (PLATFORM_CONSOLE.md § 2
 * "Security"): failed sign-ins and lockouts in the last 24 hours (from
 * `authentication_logs`, customers and staff apart), support sessions open
 * now, and staff sign-ins in the last 24 hours. Counts, plus the staff
 * members' names (staff, not customers); never an identifier or an IP.
 */
final class SecurityHealth
{
    public const array FAILURES = ['login_failed', '2fa_failed', 'turnstile_failed'];

    /** @return array{customers: int, staff: int, lockouts: int} */
    public function failures(CarbonInterface $since): array
    {
        $row = AuthenticationLog::query()->toBase()->where('created_at', '>=', $since)
            ->selectRaw('SUM(CASE WHEN authenticatable_type = ? AND event IN (?, ?, ?) THEN 1 ELSE 0 END) AS customers', ['user', ...self::FAILURES])
            ->selectRaw('SUM(CASE WHEN authenticatable_type = ? AND event IN (?, ?, ?) THEN 1 ELSE 0 END) AS staff', ['platform_staff', ...self::FAILURES])
            ->selectRaw('SUM(CASE WHEN event = ? THEN 1 ELSE 0 END) AS lockouts', ['lockout'])
            ->first();

        return ['customers' => (int) ($row->customers ?? 0), 'staff' => (int) ($row->staff ?? 0), 'lockouts' => (int) ($row->lockouts ?? 0)];
    }

    /** @return array{read_only: int, write: int} */
    public function openSupportSessions(CarbonInterface $now): array
    {
        $counts = ImpersonationSession::query()->toBase()->whereNull('ended_at')->where('expires_at', '>', $now)
            ->groupBy('mode')->select('mode')->selectRaw('COUNT(*) AS sessions')->pluck('sessions', 'mode');

        return ['read_only' => (int) ($counts->get('read_only') ?? 0), 'write' => (int) ($counts->get('write') ?? 0)];
    }

    /** @return list<array{name: string, at: CarbonImmutable, new_device: bool, country: string|null}> newest first */
    public function staffSignIns(CarbonInterface $since, int $limit = 10): array
    {
        return array_values(DB::table('authentication_logs')
            ->leftJoin('platform_staff', 'platform_staff.id', '=', 'authentication_logs.authenticatable_id')
            ->where('authentication_logs.authenticatable_type', 'platform_staff')
            ->where('authentication_logs.event', 'login_success')
            ->where('authentication_logs.created_at', '>=', $since)
            ->orderByDesc('authentication_logs.id')
            ->limit($limit)
            ->get(['platform_staff.name', 'authentication_logs.created_at', 'authentication_logs.is_new_device', 'authentication_logs.location'])
            ->map(static fn (object $row): array => [
                'name' => is_string($row->name) ? $row->name : 'A removed staff account',
                'at' => CarbonImmutable::parse((string) $row->created_at),
                'new_device' => (bool) $row->is_new_device,
                'country' => is_string($row->location) && $row->location !== '' ? $row->location : null,
            ])
            ->all());
    }

    public function staffSignInCount(CarbonInterface $since): int
    {
        return AuthenticationLog::query()->where('authenticatable_type', 'platform_staff')->where('event', 'login_success')->where('created_at', '>=', $since)->count();
    }
}
