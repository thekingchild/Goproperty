<?php

declare(strict_types=1);

namespace Modules\Tickets\Livewire\Portal;

use App\Branding\BrandColours;
use App\Branding\Branding;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Tenants\Contracts\PortalTenant;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Lists\TicketParties;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Portal\TenantTickets;
use Modules\Tickets\Tickets\TicketHistory;
use Modules\Tickets\Tickets\TicketService;

/**
 * One of my tickets, /portal/tickets/{ticket} (P04-01): the agency in its
 * colours, the number, status, home, category, priority, the problem as
 * reported and what happened since (the status history with each note).
 * While it is resolved: "Confirm it's fixed" (it closes) and "Reopen" with
 * what is still wrong; once closed, "Reopen" while the window lasts
 * (`tickets.reopen_days` after the resolution; the page says until when).
 * A draft links to its form. Looked up among the user's own tickets on
 * every request (Portal\TenantTickets), so anyone else's answers 404; the
 * actions go through Tickets\TicketService inside the agency's
 * organization. The thread of messages (P04-02) goes in the page's
 * "Conversation" slot.
 */
#[Layout('layouts::app')]
#[Title('Repair')]
final class PortalTicketShow extends Component
{
    #[Locked]
    public int $ticketId;

    #[Locked]
    public ?string $notice = null;

    public string $note = '';

    public string $reason = '';

    public function mount(TenantTickets $tickets, int $ticket): void
    {
        $this->ticketId = $ticket;
        $this->found($tickets);
    }

    public function confirm(TenantTickets $tickets, TicketService $service): void
    {
        [$tenancy, $ticket] = $this->found($tickets);
        $tickets->within($tenancy, fn () => $service->confirmResolution($this->user(), $ticket, $this->note));
        $this->reset('note', 'reason');
        $this->notice = __('Thank you. The ticket is closed.');
    }

    public function reopen(TenantTickets $tickets, TicketService $service): void
    {
        [$tenancy, $ticket] = $this->found($tickets);
        $tickets->within($tenancy, fn () => $service->reopenAsTenant($this->user(), $ticket, $this->reason));
        $this->reset('note', 'reason');
        $this->notice = __('The ticket is open again. The agency has been told what is still wrong.');
    }

    public function render(TenantTickets $tickets, TicketParties $parties, TicketHistory $history, TicketService $service): View
    {
        $user = $this->user();
        [$tenancy, $ticket] = $this->found($tickets);
        $branding = Branding::fromOrganization($tenancy->organization);

        $state = $tickets->within($tenancy, static function () use ($user, $ticket, $parties, $history, $service): array {
            $until = $service->reopenableUntil($ticket);
            $canReopen = $until !== null && now()->lessThanOrEqualTo($until) && $user->can('reopenAsTenant', $ticket);

            return [
                'row' => $parties->rows([$ticket])[0],
                'history' => $history->of($ticket, $user->id),
                'canConfirm' => $ticket->status === TicketStatus::Resolved && $user->can('closeAsTenant', $ticket),
                'canReopen' => $canReopen,
                'reopenableUntil' => $until,
                'canEdit' => $ticket->status === TicketStatus::Draft && $user->can('updateAsTenant', $ticket),
            ];
        });

        return view('tickets::livewire.portal.ticket-show', $state + [
            'ticket' => $ticket,
            'userId' => $user->id,
            'agency' => [
                'id' => $tenancy->organization->id,
                'name' => $branding->displayName(),
                'colour' => BrandColours::textShade($branding->primaryColor),
                'initials' => $branding->initials(),
                'readOnly' => $tenancy->readOnly(),
            ],
            'flash' => session('portal-ticket-status'),
        ]);
    }

    /** @return array{PortalTenant, Ticket} */
    private function found(TenantTickets $tickets): array
    {
        $found = $tickets->find($this->user(), $this->ticketId);
        abort_if($found === null, 404);

        return $found;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
