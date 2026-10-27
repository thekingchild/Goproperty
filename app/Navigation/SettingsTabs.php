<?php

declare(strict_types=1);

namespace App\Navigation;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use LogicException;

/**
 * The tabs across the workspace's Settings pages (the
 * `x-workspace.settings-nav` component), like WorkspaceNavigation for the
 * sidebar: Core registers its own pages and a module adds its settings
 * pages from its service provider, so the tabs never name a module:
 *
 *     $this->callAfterResolving(SettingsTabs::class, fn (SettingsTabs $tabs) => $tabs->add(new SettingsTab(…)));
 *
 * Shown in `order` (Core: Profile 10, Payment collection 20, Branding 30,
 * Members 90; modules' pages in between: online payments 25, P03-08;
 * document settings 40, P03-03b), only to members whose `visible` check
 * passes and only while the route exists. A module's settings page is
 * named `workspace.settings.documents.*` (its address
 * /o/{slug}/settings/documents/…) or, for how tenants pay,
 * `workspace.settings.payments.*`, which lights up the sidebar's Settings
 * item.
 */
final class SettingsTabs
{
    /** @var array<string, SettingsTab> */
    private array $tabs = [];

    /** The registry with Core's own settings pages. */
    public static function withCoreTabs(): self
    {
        $tabs = new self;
        $tabs->add(new SettingsTab('profile', 'Profile', 'workspace.settings.profile', static fn (User $user, Organization $organization): bool => $user->can('viewSettings', $organization), 10));
        $tabs->add(new SettingsTab('collections', 'Payment collection', 'workspace.settings.collections', static fn (User $user, Organization $organization): bool => $user->can('viewPaymentSettings', $organization), 20));
        $tabs->add(new SettingsTab('branding', 'Branding', 'workspace.settings.branding', static fn (User $user, Organization $organization): bool => $user->can('viewSettings', $organization), 30));
        $tabs->add(new SettingsTab('members', 'Members', 'workspace.settings.members', static fn (User $user): bool => $user->can('viewAny', Membership::class), 90));

        return $tabs;
    }

    public function add(SettingsTab $tab): void
    {
        if (isset($this->tabs[$tab->key])) {
            throw new LogicException("The settings pages already have a tab \"{$tab->key}\".");
        }

        $this->tabs[$tab->key] = $tab;
    }

    /**
     * The tabs this member may open in the organization, in order.
     *
     * @return list<array{key: string, route: string, label: string}>
     */
    public function for(User $user, Organization $organization): array
    {
        $tabs = array_values($this->tabs);
        usort($tabs, static fn (SettingsTab $a, SettingsTab $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);
        $visible = [];

        foreach ($tabs as $tab) {
            if (Route::has($tab->route) && ($tab->visible)($user, $organization)) {
                $visible[] = ['key' => $tab->key, 'route' => $tab->route, 'label' => $tab->label];
            }
        }

        return $visible;
    }
}
