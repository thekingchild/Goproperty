<?php

declare(strict_types=1);

namespace Modules\Properties\Livewire\OwnerPortal;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Properties\Contracts\ReadOnlyOwnerPage;
use Modules\Properties\OwnerPortal\OwnerPortalRecords;

/**
 * A tenant of the owner's buildings, /owner/tenants/{id} (P03-16; product
 * owner 2026-10-29): name, phone, email, address, occupation, emergency
 * contact, KYC status, ID type and the ID number masked to its last four
 * characters (the full number never reaches this page or its Livewire
 * payload: only the id is a property), and the tenant's leases in the
 * owner's buildings. Found only for a tenant who holds or held an
 * activated lease there. Anything else answers 404, like a missing record.
 * Read-only.
 */
#[Layout('layouts::app')]
#[Title('Tenant')]
final class OwnerTenantShow extends Component implements ReadOnlyOwnerPage
{
    #[Locked]
    public int $tenantId;

    public function mount(int $tenant): void
    {
        $this->tenantId = $tenant;
    }

    public function render(OwnerPortalRecords $records): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return view('properties::livewire.owner-portal.tenant-show', [
            'page' => $records->tenantPage($user, $this->tenantId) ?? abort(404),
        ]);
    }
}
