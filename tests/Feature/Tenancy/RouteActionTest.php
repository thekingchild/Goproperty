<?php

declare(strict_types=1);

use App\Tenancy\ImpersonationGuard;
use App\Tenancy\OrganizationWriteGuard;
use App\Tenancy\RouteAction;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Http\InvokableGuardProbe;
use Tests\Fixtures\Http\WorkspaceWriteProbe;

/*
 * Route::getActionMethod() answers the class name for an invokable
 * controller; the guards read their attributes through RouteAction so a
 * mark on __invoke is found (it was silently ignored before).
 */
it('reads the guard attributes of an invokable controller from __invoke', function () {
    $route = Route::get('/_probe/invokable-guard', InvokableGuardProbe::class);
    $method = RouteAction::method($route);

    expect($route->getActionMethod())->toBe(InvokableGuardProbe::class)
        ->and($method)->toBe('__invoke')
        ->and(app(ImpersonationGuard::class)->marked(InvokableGuardProbe::class, $method))->toBeTrue()
        ->and(app(OrganizationWriteGuard::class)->methodAllowed(InvokableGuardProbe::class, $method))->toBeTrue();
});

it('keeps the named method of an ordinary controller action', function () {
    $route = Route::get('/_probe/named-action', [WorkspaceWriteProbe::class, 'export']);

    expect(RouteAction::method($route))->toBe('export');
});
