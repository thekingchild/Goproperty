<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Installer\Upgrades\UpgradeMaintenance;
use App\Platform\Console\ConsoleRequests;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as LaravelPreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's maintenance mode, with one difference: while it is on for an
 * upgrade (App\Installer\Upgrades\UpgradeMaintenance, INSTALLER.md § 10)
 * the console stays open — its pages, Livewire updates sent from them and
 * Livewire's scripts — so the super-admin can run the upgrade step on a
 * host without SSH. Everyone else gets the 503 page as usual; console
 * pages still need a signed-in staff member with 2FA. Maintenance mode
 * from `php artisan down` closes everything, as before.
 */
final class PreventRequestsDuringMaintenance extends LaravelPreventRequestsDuringMaintenance
{
    public function __construct(
        Application $app,
        private readonly ConsoleRequests $console,
        private readonly UpgradeMaintenance $upgrade,
    ) {
        parent::__construct($app);
    }

    /** @param  Closure(Request): Response  $next */
    public function handle($request, Closure $next): mixed
    {
        if ($this->app->maintenanceMode()->active() && $this->upgrade->forUpgrade() && $this->staysOpen($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    private function staysOpen(Request $request): bool
    {
        if ($this->console->isConsoleRequest($request)) {
            return true;
        }

        // Livewire's script and source map, which console pages load.
        $assets = array_map(static fn (string $path): string => ltrim($path, '/'), [EndpointResolver::scriptPath(), EndpointResolver::scriptPath(true), EndpointResolver::mapPath()]);

        return $request->isMethod('GET') && in_array($request->path(), $assets, true);
    }
}
