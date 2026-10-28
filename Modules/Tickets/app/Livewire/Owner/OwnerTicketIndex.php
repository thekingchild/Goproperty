<?php

declare(strict_types=1);

namespace Modules\Tickets\Livewire\Owner;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Properties\Contracts\ReadOnlyOwnerPage;
use Modules\Tickets\Owner\OwnerTickets;

/**
 * The owner portal's repairs, /owner/tickets (P04-02;
 * docs/plans/P04-subtasks.md default 8): for every agency that manages
 * buildings of the signed-in owner, in its current colours, the sent
 * tickets of those buildings, newest first — number, title, category,
 * priority, status, unit and dates. Never a tenant's draft, never a
 * contact detail, never an internal note. Read through Owner\OwnerTickets
 * (Properties' OwnerPortalAccess). Read-only (ReadOnlyOwnerPage).
 */
#[Layout('layouts::app')]
#[Title('Repairs')]
final class OwnerTicketIndex extends Component implements ReadOnlyOwnerPage
{
    public function render(OwnerTickets $tickets): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return view('tickets::livewire.owner.tickets', ['agencies' => $tickets->agencies($user)]);
    }
}
