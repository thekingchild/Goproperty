<?php

declare(strict_types=1);

namespace App\Platform\Staff;

use App\Models\PlatformStaff;
use App\Models\StaffRole;
use App\Models\StaffStatus;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * "At least two active super-admins must exist" (PLATFORM_CONSOLE.md § 1).
 * Right after install there is only one, so the rule is: the number of
 * active super-admins never drops below min(current, 2). Once two or more
 * exist, no change may leave fewer than two; while only one exists, the
 * console shows a warning and nothing may leave none.
 */
final class SuperAdminFloor
{
    public const int REQUIRED = 2;

    public function activeCount(): int
    {
        $role = $this->role();

        if ($role === null) {
            return 0;
        }

        return PlatformStaff::query()
            ->where('status', StaffStatus::Active)
            ->whereHas('roles', fn ($query) => $query->whereKey($role->getKey()))
            ->count();
    }

    /** True while fewer than two active super-admins exist (the console warns). */
    public function belowRequired(): bool
    {
        return $this->activeCount() < self::REQUIRED;
    }

    /**
     * Runs a change that may take an active super-admin away, refusing it
     * if it would break the floor. Serialised on the super-admin role row,
     * so two removals at once cannot both pass.
     *
     * @template T
     *
     * @param  Closure(): T  $change
     * @return T
     */
    public function guard(PlatformStaff $losing, Closure $change): mixed
    {
        return DB::transaction(function () use ($losing, $change): mixed {
            $role = $this->role();

            if ($role !== null) {
                StaffRole::query()->whereKey($role->getKey())->lockForUpdate()->first();
            }

            $fresh = PlatformStaff::query()->whereKey($losing->getKey())->first();

            if ($fresh !== null && $fresh->status === StaffStatus::Active && $fresh->isSuperAdmin()) {
                $count = $this->activeCount();
                $after = $count - 1;

                if ($after < min($count, self::REQUIRED)) {
                    throw new StaffChangeRefused($count >= self::REQUIRED
                        ? 'At least two active super-admins must remain. Make someone else a super-admin first.'
                        : 'This is the only active super-admin. Make someone else a super-admin first.');
                }
            }

            return $change();
        });
    }

    private function role(): ?StaffRole
    {
        return StaffRole::query()
            ->where('name', DefaultStaffRole::SuperAdmin->value)
            ->where('is_default', true)
            ->first();
    }
}
