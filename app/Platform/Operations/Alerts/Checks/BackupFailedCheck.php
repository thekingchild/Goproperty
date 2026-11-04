<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts\Checks;

use App\Platform\Operations\Alerts\AlertCheck;
use App\Platform\Operations\Alerts\AlertCondition;
use App\Platform\Operations\Alerts\AlertRule;
use App\Platform\Operations\Alerts\AlertSettings;
use App\Platform\Operations\Alerts\Lagos;
use App\Platform\Operations\Alerts\OperationsAlert;
use App\Platform\Operations\Health\BackupHealth;
use Carbon\CarbonImmutable;

/**
 * A failed backup (PLATFORM_CONSOLE.md § 2 alerts, § 4): the latest
 * database or files backup at an active destination failed, a backup found
 * no destination, or the latest verify failed; each until a later one
 * succeeds (Back up now in Operations → Backups clears it at once).
 */
final readonly class BackupFailedCheck implements AlertCheck
{
    public function __construct(private BackupHealth $backups) {}

    public function rule(): AlertRule
    {
        return AlertRule::BackupFailed;
    }

    public function failing(CarbonImmutable $now, AlertSettings $settings): array
    {
        return array_map(static fn ($failure): AlertCondition => new AlertCondition(
            AlertRule::BackupFailed,
            $failure->subject,
            "{$failure->what} failed at ".Lagos::time($failure->failedAt).'. The reason is in Operations → Backups.',
            ['backup' => $failure->subject],
        ), $this->backups->failures());
    }

    public function cleared(OperationsAlert $incident, CarbonImmutable $now): string
    {
        return $incident->subject === 'verify' ? 'A backup verify passed again.' : 'A later backup succeeded.';
    }
}
