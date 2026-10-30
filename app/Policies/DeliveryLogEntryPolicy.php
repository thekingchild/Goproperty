<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformStaff;
use App\Platform\Deliveries\DeliveryLogEntry;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Operations → Delivery log (P05-02): `delivery_logs.view`
 * (support agents, operations managers, super-admins). Read-only.
 */
final class DeliveryLogEntryPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::DeliveryLogsView);
    }

    public function view(PlatformStaff $actor, DeliveryLogEntry $entry): bool
    {
        return $this->viewAny($actor);
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, DeliveryLogEntry $entry): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, DeliveryLogEntry $entry): bool
    {
        return false;
    }
}
