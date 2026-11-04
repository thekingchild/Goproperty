<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts\Checks;

use App\Platform\Deliveries\Health\MessagingHealth;
use App\Platform\Deliveries\Health\ProviderHealth;
use App\Platform\Operations\Alerts\AlertCheck;
use App\Platform\Operations\Alerts\AlertCondition;
use App\Platform\Operations\Alerts\AlertRule;
use App\Platform\Operations\Alerts\AlertSettings;
use App\Platform\Operations\Alerts\OperationsAlert;
use Carbon\CarbonImmutable;

/**
 * A messaging provider judged "Failing" on the dashboard (P05-05: 25 % or
 * more of the last 24 hours' attempts failed or not delivered), with at
 * least 10 attempts so one unlucky message does not page anyone. Subject:
 * `channel:provider`, e.g. `sms:gotext`.
 */
final readonly class MessagingProviderCheck implements AlertCheck
{
    public const int MIN_ATTEMPTS = 10;

    public function __construct(private MessagingHealth $messaging) {}

    public function rule(): AlertRule
    {
        return AlertRule::MessagingProvider;
    }

    public function failing(CarbonImmutable $now, AlertSettings $settings): array
    {
        $failing = [];

        foreach ($this->messaging->providers($now) as $provider) {
            if ($provider->attempted >= self::MIN_ATTEMPTS && $provider->health() === ProviderHealth::Failing) {
                $failing[] = new AlertCondition(
                    AlertRule::MessagingProvider,
                    $provider->channel.':'.$provider->provider,
                    strtoupper($provider->channel === 'whatsapp' ? 'WhatsApp' : $provider->channel)." through {$provider->label()}: {$provider->problems} of {$provider->attempted} messages failed or were not delivered in the last 24 hours ({$provider->failureRate()}).",
                    ['attempted' => $provider->attempted, 'problems' => $provider->problems],
                );
            }
        }

        return $failing;
    }

    public function cleared(OperationsAlert $incident, CarbonImmutable $now): string
    {
        return "Messages through {$incident->subject} are no longer failing at 25 % or more.";
    }
}
