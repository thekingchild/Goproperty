<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Plans\Feature;
use App\Plans\PlanGate;
use App\Tenancy\RouteAction;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `plan` route middleware (P06-01b; App\Plans\PlanGate): refuses a
 * workspace route when the organization's plan lacks each feature named
 * (`plan:own_paystack`), and checks #[RequiresFeature] / #[CountsAgainstLimit]
 * on the controller action. Runs after SetOrganizationContext; outside an
 * organization it refuses nothing.
 */
final readonly class EnsurePlanAllows
{
    public function __construct(private PlanGate $gate) {}

    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        foreach ($features as $key) {
            $this->gate->ensureFeature(Feature::from($key));
        }

        $route = $request->route();
        $controller = $route instanceof Route ? $route->getControllerClass() : null;

        if ($route instanceof Route && $controller !== null) {
            $this->gate->ensureCallAllowed($controller, RouteAction::method($route));
        }

        return $next($request);
    }
}
