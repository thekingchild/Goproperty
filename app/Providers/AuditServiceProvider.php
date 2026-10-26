<?php

declare(strict_types=1);

namespace App\Providers;

use App\Audit\AuditContext;
use App\Audit\AuthenticationEvents;
use App\Models\PlatformStaff;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Audit log and authentication log (PLATFORM_CONSOLE.md § 7–8).
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AuditContext::class);
    }

    public function boot(): void
    {
        Event::subscribe(AuthenticationEvents::class);

        // Who did it: an explicit causer when given; else the staff member on
        // console requests (their own session cookie), else the customer.
        $this->app->make(CauserResolver::class)->resolveUsing(function (mixed $subject): ?Model {
            if ($subject instanceof Model) {
                return $subject;
            }

            $staff = auth('staff')->user();

            if ($staff instanceof PlatformStaff) {
                return $staff;
            }

            $user = auth('web')->user();

            return $user instanceof User ? $user : null;
        });
    }
}
