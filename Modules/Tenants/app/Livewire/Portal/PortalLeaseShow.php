<?php

declare(strict_types=1);

namespace Modules\Tenants\Livewire\Portal;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Tenants\Portal\AgencyRentals;
use Modules\Tenants\Portal\PortalLease;
use Modules\Tenants\Portal\PortalRentals;

/**
 * One of my leases, /portal/leases/{lease} (P03-11a): the home, the agency,
 * the lease's status, contract number, billing cycle, dates and rent, and
 * the lease's bills. Draft leases are not shown. Looked up among the user's
 * own tenant profiles on every request (Portal\PortalRentals::lease), so
 * anyone else's lease answers 404. Read-only: no actions.
 */
#[Layout('layouts::app')]
#[Title('Lease')]
final class PortalLeaseShow extends Component
{
    #[Locked]
    public int $leaseId;

    public function mount(PortalRentals $rentals, int $lease): void
    {
        $this->leaseId = $lease;
        $this->found($rentals);
    }

    public function render(PortalRentals $rentals): View
    {
        [$agency, $lease] = $this->found($rentals);

        return view('tenants::livewire.portal.lease-show', ['agency' => $agency, 'home' => $lease]);
    }

    /** @return array{AgencyRentals, PortalLease} */
    private function found(PortalRentals $rentals): array
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $found = $rentals->lease($user, $this->leaseId);
        abort_if($found === null, 404);

        return $found;
    }
}
