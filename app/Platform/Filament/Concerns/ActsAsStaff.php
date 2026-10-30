<?php

declare(strict_types=1);

namespace App\Platform\Filament\Concerns;

use App\Models\PlatformStaff;
use App\Platform\Approvals\ApprovalRefused;
use App\Platform\Compliance\TierReviewRefused;
use App\Platform\Customers\CustomerChangeRefused;
use App\Platform\Impersonation\ImpersonationRefused;
use App\Platform\Plans\PlanChangeRefused;
use App\Platform\Staff\StaffChangeRefused;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * For console pages that change staff, roles, approvals, customers or tier
 * applications, or start support sessions: the signed-in staff member, and
 * refusals from the services shown as a notification.
 */
trait ActsAsStaff
{
    protected static function actor(): PlatformStaff
    {
        $staff = Filament::auth()->user();

        if (! $staff instanceof PlatformStaff) {
            throw new AuthorizationException;
        }

        return $staff;
    }

    /**
     * Runs a change; a refusal becomes a red notification instead of an error.
     *
     * @param  Closure(): (string|null)  $change  returns the success message
     */
    protected static function attempt(Closure $change): bool
    {
        try {
            $message = $change();
        } catch (StaffChangeRefused|ApprovalRefused|CustomerChangeRefused|ImpersonationRefused|TierReviewRefused|PlanChangeRefused $refused) {
            Notification::make()->title($refused->getMessage())->danger()->send();

            return false;
        }

        if ($message !== null) {
            Notification::make()->title($message)->success()->send();
        }

        return true;
    }
}
