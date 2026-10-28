<?php

declare(strict_types=1);

namespace App\Navigation;

/**
 * One tab of the tenant portal (/portal): a page of a module (PortalTabs).
 * `active` lists the route-name patterns that light it up. Every portal
 * page checks the user's own records itself; the tabs only link.
 */
final readonly class PortalTab
{
    /** @param  list<string>  $active */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string $route,
        public array $active,
        public int $order = 100,
    ) {}
}
