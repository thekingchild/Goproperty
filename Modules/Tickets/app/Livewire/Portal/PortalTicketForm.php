<?php

declare(strict_types=1);

namespace Modules\Tickets\Livewire\Portal;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Tenants\Contracts\PortalTenant;
use Modules\Tickets\Enums\TicketCategory;
use Modules\Tickets\Enums\TicketPriority;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Portal\PortalHome;
use Modules\Tickets\Portal\TenantTickets;
use Modules\Tickets\Tickets\TicketInput;
use Modules\Tickets\Tickets\TicketService;

/**
 * Report a problem, /portal/tickets/new, and change a draft,
 * /portal/tickets/{ticket}/edit (P04-01; docs/plans/P04-subtasks.md
 * default 3): which home (the user's live leases, in every agency they
 * rent from; a draft stays with its agency), what kind of problem, how
 * urgent, a title and a description. "Send" raises the ticket with the
 * agency at once (it gets its number); "Save as draft" keeps it for later,
 * seen by nobody else; a draft can be discarded. Everything goes through
 * Tickets\TicketService inside the agency's organization, which checks
 * TicketPolicy (…AsTenant) and the home again. Phone first: 360 px, 44 px
 * targets, no autofocus.
 */
#[Layout('layouts::app')]
#[Title('Report a problem')]
final class PortalTicketForm extends Component
{
    #[Locked]
    public ?int $ticketId = null;

    public string $home = '';

    public string $category = '';

    public string $priority = 'medium';

    public string $title = '';

    public string $description = '';

    public function mount(TenantTickets $tickets, ?int $ticket = null): void
    {
        $user = $this->user();

        if ($ticket === null) {
            // With no home to report for (no live lease, or the agency's account on hold), the page says so.
            $homes = $tickets->homes($user);
            $this->home = count($homes) === 1 ? $homes[0]->key : '';

            return;
        }

        [$tenancy, $found] = $this->draft($tickets, $ticket);
        $this->ticketId = $found->id;
        $this->home = $tenancy->organization->id.'-'.$found->lease_id;
        $this->category = $found->category->value;
        $this->priority = $found->priority->value;
        $this->title = $found->title;
        $this->description = (string) $found->description;
    }

    /** "Send": save and raise it with the agency. */
    public function send(TenantTickets $tickets, TicketService $service): void
    {
        $this->store($tickets, $service, send: true);
    }

    /** "Save as draft". */
    public function saveDraft(TenantTickets $tickets, TicketService $service): void
    {
        $this->store($tickets, $service, send: false);
    }

    public function discard(TenantTickets $tickets, TicketService $service): void
    {
        abort_if($this->ticketId === null, 404);
        [$tenancy, $ticket] = $this->draft($tickets, $this->ticketId);

        $tickets->within($tenancy, fn () => $service->discardDraft($this->user(), $ticket));
        session()->flash('portal-ticket-status', __('The draft was discarded.'));
        $this->redirectRoute('portal.tickets.index', navigate: true);
    }

    public function render(TenantTickets $tickets): View
    {
        $user = $this->user();
        $organizationId = $this->ticketId !== null ? (int) explode('-', $this->home)[0] : null;

        return view('tickets::livewire.portal.ticket-form', [
            'homes' => $tickets->homes($user, $organizationId),
            'editing' => $this->ticketId !== null,
            'categories' => TicketCategory::cases(),
            'priorities' => TicketPriority::cases(),
            'titleMax' => TicketInput::TITLE_MAX,
            'descriptionMax' => TicketInput::DESCRIPTION_MAX,
        ]);
    }

    private function store(TenantTickets $tickets, TicketService $service, bool $send): void
    {
        $user = $this->user();
        $home = $this->chosenHome($tickets);
        $tenancy = $tickets->tenancy($user, $home->organizationId) ?? abort(404);
        $input = ['category' => $this->category, 'priority' => $this->priority, 'title' => $this->title, 'description' => $this->description];

        if ($send) {
            // Checked as for a sent ticket first: a draft may leave the description for later.
            TicketInput::validated($input);
        }

        $ticket = $tickets->within($tenancy, function () use ($tickets, $service, $user, $home, $input, $send): Ticket {
            if ($this->ticketId === null) {
                $ticket = $service->draftAsTenant($user, $home->leaseId, $input);
                // The form edits this draft from now on, should sending it be refused.
                $this->ticketId = $ticket->id;
            } else {
                [, $draft] = $this->draft($tickets, $this->ticketId);
                $ticket = $service->updateDraft($user, $draft, $home->leaseId, $input);
            }

            if ($send) {
                $ticket = $service->submit($user, $ticket);
            }

            return $ticket;
        });

        session()->flash('portal-ticket-status', $send
            ? __('Sent to :agency as :number. You will see here when they work on it.', ['agency' => $home->agency, 'number' => $ticket->number])
            : __('Saved as a draft. Nobody else sees it until you send it.'));
        $this->redirectRoute($send ? 'portal.tickets.show' : 'portal.tickets.index', $send ? ['ticket' => $ticket->id] : [], navigate: true);
    }

    /** @throws ValidationException */
    private function chosenHome(TenantTickets $tickets): PortalHome
    {
        foreach ($tickets->homes($this->user()) as $home) {
            if ($home->key === $this->home) {
                return $home;
            }
        }

        throw ValidationException::withMessages(['home' => __('Choose one of the homes you rent.')]);
    }

    /** @return array{PortalTenant, Ticket} the user's own draft, else 404 */
    private function draft(TenantTickets $tickets, int $ticketId): array
    {
        $found = $tickets->find($this->user(), $ticketId);
        abort_if($found === null || $found[1]->status !== TicketStatus::Draft, 404);

        return $found;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
