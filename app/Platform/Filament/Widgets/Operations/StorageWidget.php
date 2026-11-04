<?php

declare(strict_types=1);

namespace App\Platform\Filament\Widgets\Operations;

use App\Platform\Operations\Health\StorageHealth;
use App\Platform\Staff\StaffPermission as P;
use Carbon\CarbonImmutable;

/** Storage (PLATFORM_CONSOLE.md § 2): uploaded files by disk, the database's size and its largest tables with their growth. */
final class StorageWidget extends OperationsWidget
{
    protected static ?int $sort = 50;

    protected string $view = 'console.operations.storage';

    public static function canView(): bool
    {
        return self::allowed(P::OperationsView, P::SystemStatus);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $health = app(StorageHealth::class);

        return ['database' => $health->database(CarbonImmutable::now()), 'media' => $health->media()];
    }
}
