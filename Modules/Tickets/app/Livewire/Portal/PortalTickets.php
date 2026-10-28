<?php

declare(strict_types=1);

namespace Modules\Tickets\Livewire\Portal;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Tickets\Portal\TenantTickets;

/**
 * Repairs, /portal/tickets: the tenant's inbox (P04-01's list, by status
 * since P04-03). "Report a problem", two tabs with their counts across
 * agencies — Open (drafts, "Fixed? Please check" for a resolved ticket,
 * being dealt with) and Finished (closed) — and, per agency the user rents
 * from, in its colours, that tab's tickets (newest first). The tab is kept
 * in the address (`?show=finished`). Read through Portal\TenantTickets;
 * read-only (the actions are on a ticket's page and the form).
 */
#[Layout('layouts::app')]
#[Title('Repairs')]
final class PortalTickets extends Component
{
    public const string OPEN = 'open';

    public const string FINISHED = 'finished';

    /** `open` or `finished`. */
    #[Url(except: self::OPEN)]
    public string $show = self::OPEN;

    public function render(TenantTickets $tickets): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $agencies = $tickets->agencies($user);

        return view('tickets::livewire.portal.tickets', [
            'agencies' => $agencies,
            'finished' => $this->show === self::FINISHED,
            'openCount' => array_sum(array_map(static fn ($agency): int => $agency->openCount(), $agencies)),
            'finishedCount' => array_sum(array_map(static fn ($agency): int => $agency->finishedCount(), $agencies)),
            'canRaise' => $tickets->homes($user) !== [],
        ]);
    }
}
