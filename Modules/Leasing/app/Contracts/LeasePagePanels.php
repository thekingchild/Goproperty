<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

/**
 * Panels other modules add to a lease's page (/o/{slug}/leases/{lease},
 * Livewire\LeaseShow): the lease's maintenance tickets with "Report a
 * repair" (the Tickets module, P04-03). Each is a Livewire component,
 * mounted with `leaseId`, that checks its own permissions and shows
 * nothing to a member who may not use it; the Leasing module only places
 * it below the lease, never knows what it does. A module that is switched
 * off registers nothing, so its panel is simply absent. Bound as a
 * singleton in LeasingServiceProvider; modules register from their
 * providers (callAfterResolving).
 */
interface LeasePagePanels
{
    /** A Livewire component name, such as `tickets::lease-tickets`. */
    public function register(string $component): void;

    /** @return list<string> the components, in the order they were registered */
    public function all(): array;
}
