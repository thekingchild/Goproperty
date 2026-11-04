<?php

declare(strict_types=1);

namespace App\Platform\Filament\Widgets\Operations;

use App\Platform\Filament\Resources\AccessLog\AccessLogResource;
use App\Platform\Operations\Health\SecurityHealth;
use App\Platform\Staff\StaffPermission as P;
use Carbon\CarbonImmutable;

/** Security (PLATFORM_CONSOLE.md § 2): failed sign-ins and lockouts in 24 hours, support sessions open now, staff sign-ins in 24 hours. */
final class SecurityWidget extends OperationsWidget
{
    protected static ?int $sort = 60;

    protected string $view = 'console.operations.security';

    public static function canView(): bool
    {
        return self::allowed(P::OperationsView, P::AccessLogView);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $health = app(SecurityHealth::class);
        $now = CarbonImmutable::now();
        $since = $now->subDay();

        return [
            'failures' => $health->failures($since),
            'sessions' => $health->openSupportSessions($now),
            'staffSignIns' => $health->staffSignIns($since),
            'staffSignInCount' => $health->staffSignInCount($since),
            'accessLogUrl' => AccessLogResource::canViewAny() ? AccessLogResource::getUrl() : null,
        ];
    }
}
