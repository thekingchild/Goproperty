<?php

declare(strict_types=1);

namespace Modules\Tenants\Livewire\Portal;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Tenants\Portal\PortalRentals;

/**
 * My bills, /portal/bills (P03-11a): every bill issued to the user, per
 * agency, each with that agency's branding as the bill was issued with it
 * (UX_DESIGN.md § 2): first the bills still to pay (due first first, the
 * amount still owed, the OVERDUE badge), then the paid and void ones.
 * Read through Portal\PortalRentals; read-only.
 */
#[Layout('layouts::app')]
#[Title('My bills')]
final class PortalBills extends Component
{
    public function render(PortalRentals $rentals): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return view('tenants::livewire.portal.bills', ['agencies' => $rentals->of($user)]);
    }
}
