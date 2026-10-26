<?php

declare(strict_types=1);

namespace App\Tenancy;

use LogicException;

/**
 * Organization data was queried or written without an organization context
 * (ARCHITECTURE.md § 4). A programming error, never a user error: fail
 * closed instead of returning every organization's rows.
 */
final class MissingOrganizationContext extends LogicException
{
    public static function forModel(string $model): self
    {
        return new self("{$model} belongs to an organization, but no organization context is set. "
            .'Set one with OrganizationContext (SetOrganizationContext does it for /o/{organization} routes; jobs use OrganizationContext::run()).');
    }
}
