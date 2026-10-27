<?php

declare(strict_types=1);

namespace App\Navigation;

use App\Models\Organization;
use App\Models\User;
use Closure;

/**
 * One item of the workspace sidebar (/o/{slug}): a page of Core or of a
 * module. `route` names the page; it may be a closure when the target
 * depends on the member (Settings opens Profile, or Payment collection for
 * someone who may only see that). `visible` says whether the member may
 * open it, normally by asking the page's policy: hiding the item is only
 * convenience, the page checks again (AGENT.md § 3 rule 4). `active` lists
 * the route-name patterns that light it up.
 */
final readonly class WorkspaceNavItem
{
    /**
     * @param  string|Closure(User, Organization): string  $route
     * @param  list<string>  $active
     * @param  Closure(User, Organization): bool  $visible
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string|Closure $route,
        public array $active,
        public Closure $visible,
        public int $order = 100,
    ) {}

    public function routeFor(User $user, Organization $organization): string
    {
        return is_string($this->route) ? $this->route : ($this->route)($user, $organization);
    }
}
