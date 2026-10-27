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
 * The tenant portal's home, /portal (P03-11a; ARCHITECTURE.md § 3,
 * UX_DESIGN.md § 2: very simple, card-based, big buttons): for every
 * agency the user rents from, what they owe and the next bill to pay, the
 * homes they lease and their latest bills. Read through
 * Portal\PortalRentals (the "linked to my user" path, each record checked
 * by TenantProfilePolicy::viewAsTenant inside its organization). Someone
 * who rents nothing here (a member opening "My rentals") sees an empty
 * state. Read-only: no actions.
 */
#[Layout('layouts::app')]
#[Title('My rentals')]
final class PortalHome extends Component
{
    public function render(PortalRentals $rentals): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return view('tenants::livewire.portal.home', ['agencies' => $rentals->of($user)]);
    }
}
