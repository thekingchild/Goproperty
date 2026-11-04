<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts\Notices;

use App\Models\PlatformStaff;
use App\Models\StaffStatus;
use App\Platform\Filament\Pages\SystemOperations;
use App\Platform\Operations\Alerts\AlertSettings;
use App\Platform\Staff\StaffPermission;
use App\Theme\ThemeContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends one alert notice to Slack (when a webhook URL is set) and by email
 * to staff: the staff chosen in Settings → Operations alerts, or, with none
 * chosen, every active staff member holding `system.status`
 * (technical-admin, super-admin). Chosen staff still need the operations
 * dashboard (`operations.view` or `system.status`). Email goes at once,
 * never through the queue. A channel that fails is logged and does not
 * stop the other.
 */
final readonly class AlertNotifier
{
    public function __construct(private SlackWebhook $slack) {}

    /** @return array{slack: bool|null, emails: int} slack null: no webhook set */
    public function send(AlertNotice $notice, AlertSettings $settings): array
    {
        $slack = null;

        if ($settings->slackWebhookUrl !== null) {
            $slack = $this->slack->post($settings->slackWebhookUrl, $notice->slackText(self::brand()));
        }

        $staff = $this->recipients($settings);
        $emails = 0;

        if ($staff->isNotEmpty()) {
            try {
                Notification::sendNow($staff, new OperationsAlertMail($notice));
                $emails = $staff->count();
            } catch (Throwable $exception) {
                Log::error('The operations alert email could not be sent ('.class_basename($exception).').');
            }
        }

        if ($slack === null && $staff->isEmpty()) {
            Log::warning('An operations alert had nowhere to go: no Slack webhook and no staff to email.', ['alert' => $notice->rule?->value]);
        }

        return ['slack' => $slack, 'emails' => $emails];
    }

    /** @return Collection<int, PlatformStaff> */
    public function recipients(AlertSettings $settings): Collection
    {
        $active = PlatformStaff::query()->where('status', StaffStatus::Active)->orderBy('id');

        if ($settings->staffIds !== []) {
            return $active->whereIn('id', $settings->staffIds)->get()
                ->filter(static fn (PlatformStaff $staff): bool => Gate::forUser($staff)->any([StaffPermission::OperationsView->value, StaffPermission::SystemStatus->value]))
                ->values();
        }

        return $active->get()->filter(static fn (PlatformStaff $staff): bool => Gate::forUser($staff)->allows(StaffPermission::SystemStatus->value))->values();
    }

    public static function dashboardUrl(): ?string
    {
        try {
            return SystemOperations::getUrl(panel: 'console');
        } catch (Throwable) {
            return null;
        }
    }

    private static function brand(): string
    {
        try {
            return app(ThemeContext::class)->current()->name();
        } catch (Throwable) {
            return (string) config('app.name');
        }
    }
}
