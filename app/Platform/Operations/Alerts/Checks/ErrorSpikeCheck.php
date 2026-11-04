<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts\Checks;

use App\Platform\Operations\Alerts\AlertCheck;
use App\Platform\Operations\Alerts\AlertCondition;
use App\Platform\Operations\Alerts\AlertRule;
use App\Platform\Operations\Alerts\AlertSettings;
use App\Platform\Operations\Alerts\OperationsAlert;
use App\Platform\Operations\Health\ErrorCounter;
use Carbon\CarbonImmutable;

/** At least `operations.alert_error_spike` exceptions (default 25) reported in the last 15 minutes (ErrorCounter; no Sentry needed). */
final readonly class ErrorSpikeCheck implements AlertCheck
{
    public const int MINUTES = 15;

    public function __construct(private ErrorCounter $errors) {}

    public function rule(): AlertRule
    {
        return AlertRule::ErrorSpike;
    }

    public function failing(CarbonImmutable $now, AlertSettings $settings): array
    {
        $errors = $this->errors->lastMinutes(self::MINUTES, $now);

        if ($errors < $settings->errorSpike) {
            return [];
        }

        return [new AlertCondition(AlertRule::ErrorSpike, '', "{$errors} errors were reported in the last ".self::MINUTES." minutes (the alert is set at {$settings->errorSpike}). The details are in the application log.", ['errors' => $errors])];
    }

    public function cleared(OperationsAlert $incident, CarbonImmutable $now): string
    {
        return 'Errors are back below the spike threshold.';
    }
}
