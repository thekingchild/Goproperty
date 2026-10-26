<?php

declare(strict_types=1);

namespace App\Tenancy;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A write refused because the organization is suspended or cancelled
 * (OrganizationWriteGuard). Answered as 403 with a message that says what
 * still works.
 */
final class OrganizationWritesBlocked extends AccessDeniedHttpException {}
