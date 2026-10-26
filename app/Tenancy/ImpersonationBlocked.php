<?php

declare(strict_types=1);

namespace App\Tenancy;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Something GoProperty staff may not do while impersonating
 * (ImpersonationGuard). Answered as 403 with the reason; already recorded
 * in the audit log as `impersonation.blocked`.
 */
final class ImpersonationBlocked extends AccessDeniedHttpException {}
