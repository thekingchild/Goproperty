<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ComplianceFlagStatus;
use App\Enums\ComplianceFlagType;
use App\Models\PlatformStaff;
use App\Platform\Compliance\ReviewFlag;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Compliance → Flags (P06-09; PLATFORM_CONSOLE.md § 1A, default
 * 99): seen and worked with `compliance.flags` (compliance-officer,
 * super-admin). Volume flags close themselves when the measure falls back,
 * so staff resolve or dismiss only the others. Extending a `volume_100`
 * grace decides a restriction: `compliance.restrict`.
 * App\Platform\Compliance\FlagQueue re-checks.
 */
final class ComplianceFlagPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::ComplianceFlags);
    }

    public function view(PlatformStaff $actor, ReviewFlag $flag): bool
    {
        return $this->viewAny($actor);
    }

    public function acknowledge(PlatformStaff $actor, ReviewFlag $flag): bool
    {
        return $this->viewAny($actor) && $flag->status === ComplianceFlagStatus::Open;
    }

    public function assign(PlatformStaff $actor, ReviewFlag $flag): bool
    {
        return $this->viewAny($actor) && $flag->isOpen() && $flag->assigned_staff_id !== $actor->id;
    }

    public function resolve(PlatformStaff $actor, ReviewFlag $flag): bool
    {
        return $this->viewAny($actor) && $flag->isOpen() && ! $flag->type->isVolume();
    }

    public function extendGrace(PlatformStaff $actor, ReviewFlag $flag): bool
    {
        return $this->viewAny($actor) && $actor->holdsStaffPermission(P::ComplianceRestrict)
            && $flag->isOpen() && $flag->type === ComplianceFlagType::Volume100;
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, ReviewFlag $flag): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, ReviewFlag $flag): bool
    {
        return false;
    }
}
