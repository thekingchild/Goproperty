<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * Laravel's AuthenticateSession (`auth.session`) for the customer side
 * (UX_DESIGN.md § 3.1; docs/security/review-2026-11.md SR-07): a session
 * keeps the password it was signed in with, and is signed out at its next
 * request once that password was changed or reset elsewhere; the session
 * that made the change keeps going (Laravel stores the new one after the
 * request). "Remember me" cookies carry the password too and stop working
 * the same way.
 *
 * One addition: the remembered password belongs to the account that was
 * signed in when it was noted. A session that signs another account in
 * (accepting an invitation, a one-time sign-in link, signing up) starts
 * afresh for that account instead of signing it straight out again.
 */
final class EndSessionsAfterPasswordChange extends AuthenticateSession
{
    /**
     * @param  Request  $request
     */
    public function handle($request, Closure $next): mixed
    {
        if ($request->hasSession() && $request->user() !== null) {
            $driver = $this->auth->getDefaultDriver();
            $owner = 'password_hash_account_'.$driver;
            $account = $request->user()->getAuthIdentifier();

            if ($request->session()->get($owner) !== $account) {
                $request->session()->forget('password_hash_'.$driver);
                $request->session()->put($owner, $account);
            }
        }

        return parent::handle($request, $next);
    }
}
