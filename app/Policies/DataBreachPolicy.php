<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DataBreach;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission as P;
use App\Privacy\Breaches\BreachStatus;

/**
 * Console → Compliance → Breach register (P09-02; NDPA 2023 s. 40):
 * `privacy.breaches` (compliance-officer, technical-admin, super-admin)
 * records and follows breaches; closed ones are read-only; none is deleted.
 */
final class DataBreachPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::PrivacyBreaches);
    }

    public function view(PlatformStaff $actor, DataBreach $breach): bool
    {
        return $this->viewAny($actor);
    }

    public function create(PlatformStaff $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(PlatformStaff $actor, DataBreach $breach): bool
    {
        return $breach->status !== BreachStatus::Closed && $this->viewAny($actor);
    }

    public function delete(PlatformStaff $actor, DataBreach $breach): bool
    {
        return false;
    }
}
