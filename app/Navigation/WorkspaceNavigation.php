<?php

declare(strict_types=1);

namespace App\Navigation;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\TierApplication;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use LogicException;

/**
 * The workspace sidebar's items (ARCHITECTURE.md § 8): Core registers its
 * own pages here and each module adds its pages from its service provider,
 * so the sidebar never names a module:
 *
 *     $this->callAfterResolving(WorkspaceNavigation::class, fn (WorkspaceNavigation $nav) => $nav->add(new WorkspaceNavItem(…)));
 *
 * Items are shown in `order` (Core: Home 0, Transaction Tier 800, Members
 * 900, Settings 1000; modules' pages in between), only to members whose
 * `visible` check passes, and only while their route exists.
 */
final class WorkspaceNavigation
{
    /** @var array<string, WorkspaceNavItem> */
    private array $items = [];

    /** The registry with Core's own workspace pages. */
    public static function withCoreItems(): self
    {
        $navigation = new self;

        $navigation->add(new WorkspaceNavItem(
            key: 'home',
            label: 'Home',
            icon: 'home',
            route: 'workspace.home',
            active: ['workspace.home'],
            visible: static fn (): bool => true,
            order: 0,
        ));

        $navigation->add(new WorkspaceNavItem(
            key: 'compliance',
            label: 'Transaction Tier',
            icon: 'shield-check',
            route: 'workspace.compliance.tier',
            active: ['workspace.compliance.*'],
            visible: static fn (User $user): bool => $user->can('viewAny', TierApplication::class),
            order: 800,
        ));

        $navigation->add(new WorkspaceNavItem(
            key: 'members',
            label: 'Members',
            icon: 'users',
            route: 'workspace.settings.members',
            active: ['workspace.settings.members'],
            visible: static fn (User $user): bool => $user->can('viewAny', Membership::class),
            order: 900,
        ));

        $navigation->add(new WorkspaceNavItem(
            key: 'settings',
            label: 'Settings',
            icon: 'cog-6-tooth',
            route: static fn (User $user, Organization $organization): string => $user->can('viewSettings', $organization) ? 'workspace.settings.profile' : 'workspace.settings.collections',
            active: ['workspace.settings.profile', 'workspace.settings.collections', 'workspace.settings.branding'],
            visible: static fn (User $user, Organization $organization): bool => $user->can('viewSettings', $organization) || $user->can('viewPaymentSettings', $organization),
            order: 1000,
        ));

        return $navigation;
    }

    public function add(WorkspaceNavItem $item): void
    {
        if (isset($this->items[$item->key])) {
            throw new LogicException("The workspace navigation already has an item \"{$item->key}\".");
        }

        $this->items[$item->key] = $item;
    }

    /** @return list<WorkspaceNavItem> every item, in order */
    public function all(): array
    {
        $items = array_values($this->items);
        usort($items, static fn (WorkspaceNavItem $a, WorkspaceNavItem $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);

        return $items;
    }

    /**
     * The items this member may open in the organization, ready to render.
     *
     * @return list<array{key: string, label: string, icon: string, url: string, active: list<string>}>
     */
    public function for(User $user, Organization $organization): array
    {
        $links = [];

        foreach ($this->all() as $item) {
            if (! ($item->visible)($user, $organization)) {
                continue;
            }

            $route = $item->routeFor($user, $organization);

            if (! Route::has($route)) {
                continue;
            }

            $links[] = [
                'key' => $item->key,
                'label' => $item->label,
                'icon' => $item->icon,
                'url' => route($route, $organization),
                'active' => $item->active,
            ];
        }

        return $links;
    }
}
