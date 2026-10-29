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
 * A lease of one of the owner's units, /owner/leases/{id} (P03-15,
 * P03-16): the terms, the tenant (name, phone, email; the rest on the
 * tenant's page), the issued contract, the lease's moves, its deposit
 * ledger, what was paid and its bills. Found among the leases
 * of the signed-in owner's own buildings (drafts never), so anything else
 * answers 404. Read-only.
 */
#[Layout('layouts::app')]
#[Title('Lease')]
final class OwnerLeaseShow extends Component implements ReadOnlyOwnerPage
{
    #[Locked]
    public int $leaseId;

    public function mount(int $lease): void
    {
        $this->leaseId = $lease;
    }

    public function render(OwnerPortalRecords $records): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return view('properties::livewire.owner-portal.lease-show', [
            'page' => $records->leasePage($user, $this->leaseId) ?? abort(404),
        ]);
    }
}
