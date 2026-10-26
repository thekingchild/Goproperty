<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * spatie/laravel-permission's team id is the current organization
 * (ARCHITECTURE.md § 4–5). It is read from OrganizationContext on every
 * call, never stored: the registrar is a singleton, the context is fresh for
 * every request and queued job, so a team id cannot outlive them. Outside an
 * organization (console, portals, jobs before OrganizationContext::run())
 * the team is null, which matches only roles without an organization.
 */
final class OrganizationTeamResolver implements PermissionsTeamResolver
{
    /**
     * Refused: setting the team by hand would let it drift from the
     * organization the request works in. Use OrganizationContext::run().
     * Null is accepted as a no-op for spatie's own resets.
     */
    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        if ($id !== null) {
            throw new LogicException('The permission team follows OrganizationContext; use OrganizationContext::run() instead of setPermissionsTeamId().');
        }
    }

    public function getPermissionsTeamId(): ?int
    {
        $context = app(OrganizationContext::class);

        return $context->has() ? $context->id() : null;
    }
}
