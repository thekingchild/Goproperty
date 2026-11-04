<?php

declare(strict_types=1);

namespace App\Platform\Filament\Widgets\Operations;

use App\Platform\Operations\Health\AppHealth;
use App\Platform\Staff\StaffPermission as P;
use Carbon\CarbonImmutable;

/** App (PLATFORM_CONSOLE.md § 2): the release, environment, maintenance mode, pending migrations and errors reported. */
final class AppStatusWidget extends OperationsWidget
{
    protected static ?int $sort = 2;

    protected string $view = 'console.operations.app-status';

    public static function canView(): bool
    {
        return self::allowed(P::OperationsView, P::SystemStatus);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['app' => app(AppHealth::class)->figures(CarbonImmutable::now())];
    }
}
