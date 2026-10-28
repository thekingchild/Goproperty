<?php

declare(strict_types=1);

namespace Modules\Tickets\Livewire\Owner;

use App\Branding\BrandColours;
use App\Branding\Branding;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Properties\Contracts\PortalOwner;
use Modules\Properties\Contracts\ReadOnlyOwnerPage;
use Modules\Tenants\Contracts\TenantDirectory;
use Modules\Tickets\Lists\TicketParties;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Owner\OwnerTickets;
use Modules\Tickets\Tickets\TicketHistory;

/**
 * A ticket of one of my buildings, /owner/tickets/{ticket} (P04-02): the
 * agency in its colours, the number, title, status, priority, category,
 * unit, the tenant (the name; phone and email only when the agency shares
 * tenants' contact details with this owner, OwnerStatements'
 * TenantContactSharing, P08-05), who handles it, the dates, the problem as
 * reported, the conversation (Owner\OwnerTicketConversation: never the
 * internal notes; the owner replies only while no agent is assigned) and
 * the status history. Found among the user's buildings on every request
 * (Owner\OwnerTickets), so anyone else's ticket answers 404. Read-only
 * (ReadOnlyOwnerPage): the reply is the conversation component's.
 */
#[Layout('layouts::app')]
#[Title('Repair')]
final class OwnerTicketShow extends Component implements ReadOnlyOwnerPage
{
    #[Locked]
    public int $ticketId;

    public function mount(OwnerTickets $tickets, int $ticket): void
    {
        $this->ticketId = $ticket;
        $this->found($tickets);
    }

    public function render(OwnerTickets $tickets, TicketParties $parties, TicketHistory $history, TenantDirectory $tenants, OrganizationContext $context): View
    {
        $user = $this->user();
        [$owner, $ticket] = $this->found($tickets);

        $state = $tickets->within($user, $owner, static function () use ($tickets, $owner, $ticket, $parties, $history, $tenants, $context): array {
            $organization = $context->get();
            $branding = Branding::fromOrganization($organization);
            $tenant = $ticket->tenant_profile_id !== null && $tickets->sharesTenantContacts($owner->ownerProfileId)
                ? $tenants->find($ticket->tenant_profile_id)
                : null;

            return [
                'row' => $parties->rows([$ticket])[0],
                'history' => $history->of($ticket),
                'contact' => $tenant === null ? null : ['phone' => $tenant->phone, 'email' => $tenant->email],
                'agency' => [
                    'id' => $organization->id,
                    'name' => $branding->displayName(),
                    'colour' => BrandColours::textShade($branding->primaryColor),
                    'initials' => $branding->initials(),
                ],
            ];
        });
        abort_if($state === null, 404);

        return view('tickets::livewire.owner.ticket-show', $state + ['ticket' => $ticket]);
    }

    /** @return array{PortalOwner, Ticket} */
    private function found(OwnerTickets $tickets): array
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
