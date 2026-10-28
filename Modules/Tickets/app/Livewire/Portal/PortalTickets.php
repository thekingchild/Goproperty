<?php

declare(strict_types=1);

namespace Modules\Tickets\Livewire\Portal;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Tickets\Portal\TenantTickets;

/**
 * Repairs, /portal/tickets (P04-01): "Report a problem", and per agency the
 * user rents from, in its colours, their drafts, the problems being dealt
 * with and the closed ones (newest first). Read through
 * Portal\TenantTickets; read-only (the actions are on a ticket's page and
 * the form). P04-03 turns it into the tenant's inbox by status.
 */
#[Layout('layouts::app')]
#[Title('Repairs')]
final class PortalTickets extends Component
{
    public function render(TenantTickets $tickets): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return view('tickets::livewire.portal.tickets', [
            'agencies' => $tickets->agencies($user),
            'canRaise' => $tickets->homes($user) !== [],
        ]);
    }
}
