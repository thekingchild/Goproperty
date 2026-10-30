<?php

declare(strict_types=1);

namespace Modules\Tenants\Contracts;

/**
 * Panels other modules add to a tenant's record (/o/{slug}/tenants/{tenant},
 * Livewire\TenantShow): the tenant's consent to the agency's WhatsApp
 * messages (the WhatsApp module, P05-06). Each is a Livewire component,
 * mounted with `tenantId`, that checks its own permissions and shows
 * nothing to a member who may not use it (or when it has nothing to say);
 * the Tenants module only places it, never knows what it does. A module
 * that is switched off registers nothing. Bound as a singleton in
 * TenantsServiceProvider; modules register from their providers
 * (callAfterResolving), like Leasing's LeasePagePanels.
 */
interface TenantPagePanels
{
    /** A Livewire component name, such as `whatsapp::tenant-opt-in`. */
    public function register(string $component): void;

    /** @return list<string> the components, in the order they were registered */
    public function all(): array;
}
