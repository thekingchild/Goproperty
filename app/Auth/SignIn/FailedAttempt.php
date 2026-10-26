<?php

declare(strict_types=1);

namespace App\Auth\SignIn;

/**
 * Set when Fortify reports wrong details during this request (its Failed
 * event), so GuardSignIn can count the failure: Fortify's pipeline turns
 * the failure into a redirect before GuardSignIn sees it. Scoped.
 */
final class FailedAttempt
{
    public bool $failed = false;
}
