<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\SetOrganizationContext;
use App\Models\User;
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
 * context on update requests too, and a suspended organization's members
 * can read but not write (OrganizationWriteGuard).
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Before any policy or permission: a customer's write ability in a
        // suspended organization is denied with the reason.
        Gate::before(function (mixed $user, string $ability, array $arguments = []): ?Response {
            return $user instanceof User ? $this->app->make(OrganizationWriteGuard::class)->inspect($ability, $arguments) : null;
        });

        Livewire::addPersistentMiddleware([SetOrganizationContext::class]);

        // Ahead of Livewire's own call hooks, which run event listeners themselves.
        before('call', function (mixed $component, mixed $method, mixed $params): void {
            $this->app->make(RefuseLivewireWritesWhileSuspended::class)($component, $method, $params);
        });
    }
}
