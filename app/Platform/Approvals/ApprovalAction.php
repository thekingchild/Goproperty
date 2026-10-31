<?php

declare(strict_types=1);

namespace App\Platform\Approvals;

use App\Enums\Concerns\HasValues;
use App\Platform\Staff\StaffPermission;
use Carbon\CarbonInterval;

/**
 * Actions under the two-person rule (PLATFORM_CONSOLE.md § 1). Each names
 * the permission a second staff member needs to approve it, and how long a
 * request stays open.
 */
enum ApprovalAction: string
{
    use HasValues;

    case Refund = 'refund';
    case ProductionRestore = 'production_restore';
    case RoleGrant = 'role_grant';
    case WriteImpersonation = 'write_impersonation';
    case DataPurge = 'data_purge';
    case TierSettings = 'tier_settings';

    public function label(): string
    {
        return match ($this) {
            self::Refund => 'Refund',
            self::ProductionRestore => 'Restore to production',
            self::RoleGrant => 'Role grant',
            self::WriteImpersonation => 'Write-mode impersonation',
            self::DataPurge => 'Data purge',
            self::TierSettings => 'Transaction Tier settings',
        };
    }

    public function approverPermission(): StaffPermission
    {
        return match ($this) {
            self::Refund => StaffPermission::RefundsApprove,
            self::ProductionRestore => StaffPermission::BackupsApproveProductionRestore,
            self::RoleGrant => StaffPermission::RolesManage,
            self::WriteImpersonation => StaffPermission::ImpersonateWrite,
            self::DataPurge => StaffPermission::DataPurge,
            self::TierSettings => StaffPermission::TiersApproveSettings,
        };
    }

    public function lifetime(): CarbonInterval
    {
        return match ($this) {
            self::WriteImpersonation => CarbonInterval::hour(),
            self::ProductionRestore => CarbonInterval::day(),
            default => CarbonInterval::days(7),
        };
    }
}
