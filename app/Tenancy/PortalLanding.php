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
 * "this user has a tenant profile somewhere", P03-01b; the Properties
 * module: "this user is linked to an owner profile somewhere", P03-15).
 * Tenants never get an `organization_user` row (P03 decision 4) and owners
 * hold only `property-owner` where they have one, so WorkspaceLanding and
 * the organization picker ask here before treating a user as someone who
 * has just signed up.
 *
 * Someone who belongs in one portal lands there; someone who belongs in
 * several (a tenant who also owns buildings) lands on the picker
 * (/dashboard), which lists the portals they belong in (P03-15). Every
 * signed-in page also links both portals (sidebar, switcher, bottom bar).
 */
final class PortalLanding
{
    /** @var list<array{route: string, label: string, icon: string, applies: Closure(User): bool}> */
    private array $portals = [];

    /** @param  Closure(User): bool  $applies */
    public function register(string $route, Closure $applies, string $label = '', string $icon = 'squares-2x2'): void
    {
        $this->portals[] = ['route' => $route, 'label' => $label, 'icon' => $icon, 'applies' => $applies];
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

    /**
     * Every portal this user belongs in, in registration order.
     *
     * @return list<array{url: string, label: string, icon: string}>
     */
    public function of(User $user): array
    {
        $portals = [];

        foreach ($this->portals as $portal) {
            if (($portal['applies'])($user)) {
                $portals[] = ['url' => route($portal['route']), 'label' => $portal['label'], 'icon' => $portal['icon']];
            }
        }

        return $portals;
    }

    /** Where someone with no workspace lands: their only portal, else null (none, or several: the picker lists them). */
    public function landing(User $user): ?string
    {
        $portals = $this->of($user);

        return count($portals) === 1 ? $portals[0]['url'] : null;
    }
}
