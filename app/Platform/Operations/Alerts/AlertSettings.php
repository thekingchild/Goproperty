<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts;

use App\Settings\SettingsStore;

/**
 * The alerts' settings as one read (Console → Settings → Operations
 * alerts; config/settings.php `operations.*`, audited by the settings
 * store). The Slack webhook URL is a secret: stored encrypted and never
 * shown back in full (only its last four characters).
 */
final readonly class AlertSettings
{
    public const array KEYS = [
        'enabled' => 'operations.alerts_enabled',
        'slack' => 'operations.alert_slack_webhook_url',
        'staff' => 'operations.alert_staff_ids',
        'scheduler_minutes' => 'operations.alert_scheduler_minutes',
        'queue_wait_minutes' => 'operations.alert_queue_wait_minutes',
        'webhook_failure_percent' => 'operations.alert_webhook_failure_percent',
        'error_spike' => 'operations.alert_error_spike',
        'renotify_minutes' => 'operations.alert_renotify_minutes',
        'uptime_token_hash' => 'operations.uptime_token_hash',
    ];

    /** @param  list<int>  $staffIds */
    public function __construct(
        public bool $enabled,
        public ?string $slackWebhookUrl,
        public array $staffIds,
        public int $schedulerMinutes,
        public int $queueWaitMinutes,
        public int $webhookFailurePercent,
        public int $errorSpike,
        public int $renotifyMinutes,
        public ?string $uptimeTokenHash,
    ) {}

    public static function current(): self
    {
        $store = app(SettingsStore::class);
        $int = static fn (string $key, int $default): int => is_int($value = $store->get(self::KEYS[$key])) ? $value : $default;
        $string = static fn (string $key): ?string => is_string($value = $store->get(self::KEYS[$key])) && $value !== '' ? $value : null;
        $staff = $store->get(self::KEYS['staff']);

        return new self(
            enabled: $store->get(self::KEYS['enabled']) !== false,
            slackWebhookUrl: $string('slack'),
            staffIds: is_array($staff) ? array_values(array_filter($staff, 'is_int')) : [],
            schedulerMinutes: $int('scheduler_minutes', 5),
            queueWaitMinutes: $int('queue_wait_minutes', 5),
            webhookFailurePercent: $int('webhook_failure_percent', 5),
            errorSpike: $int('error_spike', 25),
            renotifyMinutes: $int('renotify_minutes', 60),
            uptimeTokenHash: $string('uptime_token_hash'),
        );
    }

    /** "•••• ab12" for the settings page, never the URL itself. */
    public function maskedSlackUrl(): ?string
    {
        return $this->slackWebhookUrl === null ? null : '•••• '.substr($this->slackWebhookUrl, -4);
    }
}
