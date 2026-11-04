<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Livewire\Livewire;

/**
 * Laravel's "Confirm your password" gate (`password.confirm`), also on the
 * Livewire calls of the page it guards (docs/security/review-2026-11.md
 * SR-08). Settings → Security turns off two-factor sign-in and removes
 * passkeys with Livewire calls; Laravel's own middleware answers a JSON 423
 * on those, which Livewire ignores, so a page left open past the
 * confirmation's three hours kept working. Registered as Livewire
 * persistent middleware (FortifyServiceProvider): once the confirmation is
 * stale a call sends the browser to confirm, then back to the page.
 */
final class RequireFreshPassword extends RequirePassword
{
    /**
     * @param  Request  $request
     * @param  string|null  $redirectToRoute
     * @param  string|int|null  $passwordTimeoutSeconds
     */
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null): mixed
    {
        // Middleware parameters arrive as strings ("password.confirm:,900").
        $timeout = is_numeric($passwordTimeoutSeconds) ? (int) $passwordTimeoutSeconds : null;

        if (Livewire::isLivewireRequest() && $this->shouldConfirmPassword($request, $timeout)) {
            // $request is the page's (Livewire replays its route), not the update endpoint.
            $request->session()->put('url.intended', $request->fullUrl());

            throw new HttpResponseException(new RedirectResponse($this->urlGenerator->route($redirectToRoute ?: 'password.confirm')));
        }

        return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
    }
}
