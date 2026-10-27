<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Routing\Route;

/**
 * The controller method a route runs. For an invokable controller Laravel's
 * Route::getActionMethod() answers the class name, so the guards that read
 * #[BlockedWhileImpersonating] / #[AllowedWhileSuspended] from the method
 * would never find it; this answers `__invoke` instead.
 */
final class RouteAction
{
    public static function method(Route $route): string
    {
        $method = $route->getActionMethod();
        $controller = $route->getControllerClass();

        return $controller !== null && ($method === $controller || $method === ltrim($controller, '\\')) ? '__invoke' : $method;
    }
}
