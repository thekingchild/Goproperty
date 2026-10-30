<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmailSuppression;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Content → Suppressed addresses (PLATFORM_CONSOLE.md § 5
 * "Delivery"): support sees them with `delivery_logs.view`; adding one by
 * hand or letting email go to one again is `settings.email_provider`
 * (technical-admin). Nothing is deleted: a suppression is lifted.
 */
final class EmailSuppressionPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::DeliveryLogsView) || $actor->holdsStaffPermission(P::EmailProviderSettings);
    }

    public function view(PlatformStaff $actor, EmailSuppression $suppression): bool
    {
        return $this->viewAny($actor);
    }

    public function create(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::EmailProviderSettings);
    }

    public function lift(PlatformStaff $actor, EmailSuppression $suppression): bool
    {
        return $suppression->isActive() && $actor->holdsStaffPermission(P::EmailProviderSettings);
    }

    public function update(PlatformStaff $actor, EmailSuppression $suppression): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, EmailSuppression $suppression): bool
    {
        return false;
    }
}
