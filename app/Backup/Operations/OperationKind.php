<?php

declare(strict_types=1);

namespace App\Backup\Operations;

use App\Enums\Concerns\HasValues;

/** What a backups-console operation does (PLATFORM_CONSOLE.md § 4). */
enum OperationKind: string
{
    use HasValues;

    case Backup = 'backup';
    case Verify = 'verify';
    case RestoreStaging = 'restore_staging';
    case RestoreProduction = 'restore_production';
    case Drill = 'drill';

    public function label(): string
    {
        return match ($this) {
            self::Backup => 'Backup',
            self::Verify => 'Verify',
            self::RestoreStaging => 'Restore to staging',
            self::RestoreProduction => 'Restore to production',
            self::Drill => 'Restore drill',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::RestoreProduction => 'danger',
            self::RestoreStaging => 'warning',
            self::Drill => 'gray',
            default => 'info',
        };
    }
}
