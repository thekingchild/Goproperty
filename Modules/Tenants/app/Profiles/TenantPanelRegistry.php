<?php

declare(strict_types=1);

namespace Modules\Tenants\Profiles;

use Modules\Tenants\Contracts\TenantPagePanels;

/** The panels other modules add to a tenant's record (a singleton; Contracts\TenantPagePanels). */
final class TenantPanelRegistry implements TenantPagePanels
{
    /** @var list<string> */
    private array $components = [];

    public function register(string $component): void
    {
        if (! in_array($component, $this->components, true)) {
            $this->components[] = $component;
        }
    }

    public function all(): array
    {
        return $this->components;
    }
}
