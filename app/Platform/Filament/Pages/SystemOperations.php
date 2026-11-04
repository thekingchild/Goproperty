<?php

declare(strict_types=1);

namespace App\Platform\Filament\Pages;

use App\Platform\Filament\Widgets\Operations\AlertsWidget;
use App\Platform\Filament\Widgets\Operations\AppStatusWidget;
use App\Platform\Filament\Widgets\Operations\BackupsWidget;
use App\Platform\Filament\Widgets\Operations\MessagingDeliveriesWidget;
use App\Platform\Filament\Widgets\Operations\MessagingProvidersWidget;
use App\Platform\Filament\Widgets\Operations\OperationsWidget;
use App\Platform\Filament\Widgets\Operations\QueuesWidget;
use App\Platform\Filament\Widgets\Operations\SchedulerWidget;
use App\Platform\Filament\Widgets\Operations\SecurityWidget;
use App\Platform\Filament\Widgets\Operations\SmsSpendWidget;
use App\Platform\Filament\Widgets\Operations\StorageWidget;
use App\Platform\Filament\Widgets\Operations\WebhooksWidget;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Console → Operations → System operations: the System Operations
 * Dashboard of PLATFORM_CONSOLE.md § 2 (technical-admin, super-admin),
 * health at a glance, every widget refreshed every 60 seconds: alerts,
 * app, queues, scheduler, webhooks, messaging (P05-05), backups, storage
 * and security (P08-03), each an OperationsWidget with its own canView().
 *
 * Who opens it: anyone who may see one of its widgets. Every widget is for
 * `operations.view` (technical-admin, operations-manager, super-admin) and
 * one or two narrower permissions (system.status, queues.manage,
 * webhooks.replay, the backups permissions, access_log.view,
 * operations.alerts; the Messaging ones delivery_logs.view). Staff 2FA
 * applies as on every console page (the panel's middleware).
 */
final class SystemOperations extends Dashboard
{
    protected static string $routePath = 'operations';

    protected static ?string $slug = 'operations';

    protected static ?string $title = 'System operations';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = -1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    /** @var list<class-string<OperationsWidget>> */
    private const array WIDGETS = [
        AlertsWidget::class, AppStatusWidget::class, QueuesWidget::class, SchedulerWidget::class, WebhooksWidget::class,
        MessagingDeliveriesWidget::class, MessagingProvidersWidget::class, SmsSpendWidget::class,
        BackupsWidget::class, StorageWidget::class, SecurityWidget::class,
    ];

    public static function canAccess(): bool
    {
        foreach (self::WIDGETS as $widget) {
            if ($widget::canView()) {
                return true;
            }
        }

        return false;
    }

    public function getSubheading(): string
    {
        return 'Refreshed every minute. Counts cover every organization; times are Lagos time.';
    }

    /** @return list<class-string<OperationsWidget>> */
    public function getWidgets(): array
    {
        return self::WIDGETS;
    }

    public function getColumns(): int
    {
        return 1;
    }
}
