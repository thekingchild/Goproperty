<?php

declare(strict_types=1);

namespace Modules\Leasing\Leases;

use Modules\Leasing\Contracts\LeasePagePanels;

/** The panels other modules add to a lease's page (a singleton; Contracts\LeasePagePanels). */
final class LeasePanelRegistry implements LeasePagePanels
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
