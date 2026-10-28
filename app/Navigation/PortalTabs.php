<?php

declare(strict_types=1);

namespace App\Navigation;

use Illuminate\Support\Facades\Route;
use LogicException;

/**
 * The tenant portal's tabs (/portal; UX_DESIGN.md § 1: big targets, two a
 * row at 360 px), drawn by the `<x-portal.nav />` component on every
 * portal page. Each module adds its pages from its service provider, so
 * the portal never names a module:
 *
 *     $this->callAfterResolving(PortalTabs::class, fn (PortalTabs $tabs) => $tabs->add(new PortalTab(…)));
 *
 * The Tenants module adds Home, My bills, Payments and Documents (P03-11);
 * the Tickets module Repairs (P04-01). Tabs show in `order`, only while
 * their route exists.
 */
final class PortalTabs
{
    /** @var array<string, PortalTab> */
    private array $tabs = [];

    public function add(PortalTab $tab): void
    {
        if (array_key_exists($tab->key, $this->tabs)) {
            throw new LogicException("The portal tab [{$tab->key}] is already registered.");
        }

        $this->tabs[$tab->key] = $tab;
    }

    /** @return list<PortalTab> in order, those whose page exists */
    public function all(): array
    {
        $tabs = array_values(array_filter($this->tabs, static fn (PortalTab $tab): bool => Route::has($tab->route)));
        usort($tabs, static fn (PortalTab $a, PortalTab $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);

        return $tabs;
    }
}
