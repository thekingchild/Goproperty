<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PlatformStaff;
use App\Platform\Approvals\ApprovalAction;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Billing\RefundApproval;
use App\Platform\Compliance\TierSettingsApproval;
use App\Platform\Impersonation\WriteImpersonationApproval;
use App\Platform\Staff\RoleGrantApproval;
use App\Platform\Staff\StaffAudit;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Staff roles, lifecycle and two-person approvals (PLATFORM_CONSOLE.md § 1).
 * Later tasks register their approval handlers here (refund,
 * production_restore, data_purge) or in their own module provider;
 * write-mode impersonation's is WriteImpersonationApproval (P01-08).
 */
final class StaffServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ApprovalService::class, function ($app): ApprovalService {
            $approvals = new ApprovalService($app, $app->make(StaffAudit::class));
            $approvals->handle(ApprovalAction::RoleGrant, RoleGrantApproval::class);
            $approvals->handle(ApprovalAction::WriteImpersonation, WriteImpersonationApproval::class);
            $approvals->handle(ApprovalAction::Refund, RefundApproval::class);
            $approvals->handle(ApprovalAction::TierSettings, TierSettingsApproval::class);

            return $approvals;
        });
    }

    public function boot(): void
    {
        // "Last login" on the staff profile.
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof PlatformStaff) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });
    }
}
