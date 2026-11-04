<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts\Checks;

use App\Platform\Operations\Alerts\AlertCheck;
use App\Platform\Operations\Alerts\AlertCondition;
use App\Platform\Operations\Alerts\AlertRule;
use App\Platform\Operations\Alerts\AlertSettings;
use App\Platform\Operations\Alerts\OperationsAlert;
use App\Platform\Operations\Health\BackupHealth;
use Carbon\CarbonImmutable;

/**
 * The quarterly restore drill (PLATFORM_CONSOLE.md § 4): from the first day
 * of a quarter's last month (Lagos), a reminder when no drill dated in that
 * quarter has been recorded. Sent once, never repeated; it closes when a
 * drill is recorded or the quarter ends. Subject: the quarter, `2026-Q4`.
 */
final readonly class BackupDrillCheck implements AlertCheck
{
    public function __construct(private BackupHealth $backups) {}

    public function rule(): AlertRule
    {
        return AlertRule::BackupDrill;
    }

    public function failing(CarbonImmutable $now, AlertSettings $settings): array
    {
        $today = $now->setTimezone('Africa/Lagos');
        $start = $today->firstOfQuarter();
        $end = $today->lastOfQuarter();

        if ($today->month !== $end->month || $this->backups->drillBetween($start->toDateString(), $end->toDateString())) {
            return [];
        }

        $quarter = $today->year.'-Q'.$today->quarter;

        return [new AlertCondition(
            AlertRule::BackupDrill,
            $quarter,
            "No restore drill has been recorded for this quarter ({$quarter}). Do one and record it in Operations → Backups by ".$end->format('j M Y').'.',
            ['quarter' => $quarter],
        )];
    }

    public function cleared(OperationsAlert $incident, CarbonImmutable $now): string
    {
        [$year, $quarter] = explode('-Q', $incident->subject) + [1 => '0'];
        $start = CarbonImmutable::create((int) $year, ((int) $quarter - 1) * 3 + 1, 1, 0, 0, 0, 'Africa/Lagos');

        return $start !== null && $this->backups->drillBetween($start->toDateString(), $start->lastOfQuarter()->toDateString())
            ? "A restore drill was recorded for {$incident->subject}."
            : "{$incident->subject} ended without a restore drill recorded.";
    }
}
