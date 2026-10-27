<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\User;
use Closure;

/**
 * Where someone with no workspace lands after signing in
 * (ONBOARDING_COMPLIANCE.md § 1 "After login": tenant only → /portal,
 * owner only → /owner). Core does not know who is a tenant or an owner:
 * modules register a check from their providers (the Tenants module:
 * "this user has a tenant profile somewhere", P03-01b), in the order they
 * should win. Tenants never get an `organization_user` row (P03 decision
 * 4), so WorkspaceLanding and the organization picker ask here before
 * treating a user as someone who has just signed up.
 */
final class PortalLanding
{
    /** @var list<array{route: string, applies: Closure(User): bool}> */
    private array $portals = [];

    /** @param  Closure(User): bool  $applies */
    public function register(string $route, Closure $applies): void
    {
        $this->portals[] = ['route' => $route, 'applies' => $applies];
    }

    /** The first portal this user belongs in, or null. */
    public function url(User $user): ?string
    {
        foreach ($this->portals as $portal) {
            if (($portal['applies'])($user)) {
                return route($portal['route']);
            }
        }

        return null;
    }
}
