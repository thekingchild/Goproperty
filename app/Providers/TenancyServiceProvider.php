<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\SetOrganizationContext;
use App\Models\User;
use App\Plans\Livewire\RefuseLivewireCallsOutsidePlan;
use App\Tenancy\CurrentImpersonation;
use App\Tenancy\ImpersonationGuard;
use App\Tenancy\Livewire\RefuseLivewireCallsWhileImpersonating;
use App\Tenancy\Livewire\RefuseLivewireWritesWhileSuspended;
use App\Tenancy\OrganizationWriteGuard;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

use function Livewire\before;

/**
 * Organization-wide rules that every feature inherits (ARCHITECTURE.md § 4):
 * Livewire components inside /o/{organization} get their organization
 * context on update requests too, a suspended organization's members can
 * read but not write (OrganizationWriteGuard), and GoProperty staff
 * impersonating a user never reach the always-blocked actions, nor any
 * write in a read-only session (ImpersonationGuard).
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The support session of the current request (PLATFORM_CONSOLE.md § 6).
        $this->app->scoped(CurrentImpersonation::class);
    }

    public function boot(): void
    {
        // Before any policy or permission: what impersonating staff may not
        // do, then a customer's write ability in a suspended organization,
        // each denied with the reason.
        Gate::before(function (mixed $user, string $ability, array $arguments = []): ?Response {
            if (! $user instanceof User) {
                return null;
            }

            return $this->app->make(ImpersonationGuard::class)->inspect($user, $ability, $arguments)
                ?? $this->app->make(OrganizationWriteGuard::class)->inspect($ability, $arguments);
        });

        Livewire::addPersistentMiddleware([SetOrganizationContext::class]);

        // Ahead of Livewire's own call hooks, which run event listeners themselves.
        before('call', function (mixed $component, mixed $method, mixed $params): void {
            $this->app->make(RefuseLivewireCallsWhileImpersonating::class)($component, $method, $params);
            $this->app->make(RefuseLivewireWritesWhileSuspended::class)($component, $method, $params);
            // What the plan does not include (P06-01b): after the suspension check, whose message comes first.
            $this->app->make(RefuseLivewireCallsOutsidePlan::class)($component, $method, $params);
        });
    }
}
