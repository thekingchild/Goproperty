<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts\Checks;

use App\Platform\Operations\Alerts\AlertCheck;
use App\Platform\Operations\Alerts\AlertCondition;
use App\Platform\Operations\Alerts\AlertRule;
use App\Platform\Operations\Alerts\AlertSettings;
use App\Platform\Operations\Alerts\OperationsAlert;
use App\Platform\Operations\Health\WebhookFigures;
use App\Platform\Operations\Health\WebhookHealth;
use Carbon\CarbonImmutable;

/**
 * Paystack webhook failures above `operations.alert_webhook_failure_percent`
 * (default 5 %) over the last hour, with at least 3 deliveries, for
 * GoProperty's own account (subject `platform`) and each agency's (subject
 * `org:<id>`; the alert names the organization by number only).
 */
final readonly class WebhookFailureCheck implements AlertCheck
{
    public const int WINDOW_MINUTES = 60;

    public const int MIN_DELIVERIES = 3;

    public function __construct(private WebhookHealth $webhooks) {}

    public function rule(): AlertRule
    {
        return AlertRule::WebhookFailures;
    }

    public function failing(CarbonImmutable $now, AlertSettings $settings): array
    {
        $since = $now->subMinutes(self::WINDOW_MINUTES);
        $failing = [];

        foreach ([$this->webhooks->own($since), ...$this->webhooks->agencies($since, PHP_INT_MAX)] as $figures) {
            if ($figures->received >= self::MIN_DELIVERIES && $figures->failed * 100 > $settings->webhookFailurePercent * $figures->received) {
                $failing[] = $this->condition($figures);
            }
        }

        return $failing;
    }

    public function cleared(OperationsAlert $incident, CarbonImmutable $now): string
    {
        return 'Paystack webhooks for '.self::whose($incident->subject).' are below the failure threshold again.';
    }

    private function condition(WebhookFigures $figures): AlertCondition
    {
        $subject = $figures->organizationId === null ? 'platform' : 'org:'.$figures->organizationId;

        return new AlertCondition(
            AlertRule::WebhookFailures,
            $subject,
            "{$figures->failed} of {$figures->received} Paystack webhook deliveries for ".self::whose($subject).' failed in the last hour ('.$figures->rateLabel().'): a wrong signature or a processing error.',
            ['received' => $figures->received, 'failed' => $figures->failed],
        );
    }

    private static function whose(string $subject): string
    {
        return $subject === 'platform' ? 'the platform\'s own account' : 'organization #'.substr($subject, 4).' (its own account)';
    }
}
