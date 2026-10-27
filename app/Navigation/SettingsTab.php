<?php

declare(strict_types=1);

namespace App\Navigation;

use App\Models\Organization;
use App\Models\User;
use Closure;

/**
 * One tab of the workspace's Settings pages (/o/{slug}/settings/…): a page
 * of Core or of a module (SettingsTabs). `visible` says whether the member
 * may open it, normally by asking the page's policy; the page checks again
 * (AGENT.md § 3 rule 4).
 */
final readonly class SettingsTab
{
    /** @param  Closure(User, Organization): bool  $visible */
    public function __construct(
        public string $key,
        public string $label,
        public string $route,
        public Closure $visible,
        public int $order = 100,
    ) {}
}
