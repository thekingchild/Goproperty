<?php

declare(strict_types=1);

namespace App\Platform\Filament\Widgets\Operations;

use App\Platform\Filament\Pages\Settings\OperationsAlertSettings;
use App\Platform\Operations\Alerts\AlertSettings;
use App\Platform\Operations\Alerts\Notices\AlertNotifier;
use App\Platform\Operations\Alerts\OperationsAlert;
use App\Platform\Staff\StaffPermission as P;

/**
 * Alerts (PLATFORM_CONSOLE.md § 2 "Alerts"; P08-03): the incidents open now
 * and the last ones cleared, and where alerts go (Slack set or not, how many
 * staff are emailed), with a link to Settings → Operations alerts for whoever
 * may change them.
 */
final class AlertsWidget extends OperationsWidget
{
    protected static ?int $sort = 1;

    protected string $view = 'console.operations.alerts';

    public static function canView(): bool
    {
        return self::allowed(P::OperationsView, P::SystemStatus, P::OperationsAlerts);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $settings = AlertSettings::current();

        return [
            'open' => OperationsAlert::query()->open()->orderBy('started_at')->orderBy('id')->get(),
            'cleared' => OperationsAlert::query()->whereNull('open_key')->orderByDesc('resolved_at')->orderByDesc('id')->limit(10)->get(),
            'settings' => $settings,
            'recipients' => app(AlertNotifier::class)->recipients($settings)->count(),
            'settingsUrl' => OperationsAlertSettings::canAccess() ? OperationsAlertSettings::getUrl() : null,
        ];
    }
}
