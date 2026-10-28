<?php

declare(strict_types=1);

namespace Modules\Tickets\Livewire;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Tickets\Enums\TicketCategory;
use Modules\Tickets\Enums\TicketPriority;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Lists\TicketParties;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Tickets\Assignees;
use Modules\Tickets\Tickets\TicketHistory;
use Modules\Tickets\Tickets\TicketService;

/**
 * A ticket, /o/{organization}/tickets/{ticket} (P04-01): its number, title,
 * status, the problem as reported, the unit, building and tenant, who
 * raised it and when, its status history, and the actions its state
 * allows (TicketStatus): start review, start work, resolve (with what was
 * done), close on the tenant's word, reopen with a reason; "Assign to" (a
 * member who works on the building) and category and priority. Each action
 * checks TicketPolicy and goes through Tickets\TicketService, which checks
 * it again; a refused move shows its reason and changes nothing. The
 * thread of messages (P04-02) goes in the page's "Conversation" slot.
 *
 * The ticket id is locked and the ticket loaded through the organization
 * scope on every request, so another organization's ticket (a tenant's
 * draft, or another building's for an assigned agent) answers 404.
 */
#[Layout('layouts::app')]
#[Title('Ticket')]
final class TicketShow extends Component
{
    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $ticketId;

    #[Locked]
    public ?string $notice = null;

    /** What was done (resolve, close) or why it is reopened. */
    public string $note = '';

    public string $reason = '';

    public string $assigneeId = '';

    public string $category = '';

    public string $priority = '';

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, Ticket $ticket): void
    {
        $this->organizationId = $context->id();
        $this->authorize('view', $ticket);
        $this->ticketId = $ticket->id;
        $this->fillFrom($ticket);
    }

    public function startReview(TicketService $tickets): void
    {
        $tickets->startReview($this->user(), $this->authorized('changeStatus'));
        $this->done(__('The ticket is under review.'));
    }

    public function startWork(TicketService $tickets): void
    {
        $tickets->startWork($this->user(), $this->authorized('changeStatus'));
        $this->done(__('Work on the ticket has started.'));
    }

    public function resolve(TicketService $tickets): void
    {
        $tickets->resolve($this->user(), $this->authorized('changeStatus'), $this->note);
        $this->done(__('The ticket is resolved. The tenant can confirm it, or reopen it if it is not fixed.'));
    }

    public function close(TicketService $tickets): void
    {
        $tickets->close($this->user(), $this->authorized('changeStatus'), $this->note);
        $this->done(__('The ticket is closed.'));
    }

    public function reopen(TicketService $tickets): void
    {
        $tickets->reopen($this->user(), $this->authorized('changeStatus'), $this->reason);
        $this->done(__('The ticket was reopened.'));
    }

    public function assign(TicketService $tickets): void
    {
        $ticket = $tickets->assign($this->user(), $this->authorized('update'), ctype_digit($this->assigneeId) ? (int) $this->assigneeId : null);
        $this->done($ticket->assigned_to_user_id === null ? __('The ticket is not assigned to anyone now.') : __('The ticket was assigned.'));
    }

    public function saveDetails(TicketService $tickets): void
    {
        $tickets->changeDetails($this->user(), $this->authorized('update'), ['category' => $this->category, 'priority' => $this->priority]);
        $this->done(__('The category and priority were saved.'));
    }

    public function render(OrganizationContext $context, TicketParties $parties, TicketHistory $history, TicketService $tickets, Assignees $assignees): View
    {
        $ticket = $this->ticket();
        // Checked on every render, not only when the page opened.
        $this->authorize('view', $ticket);
        $user = $this->user();
        [$row] = $parties->rows([$ticket]);
        $manages = $user->can('changeStatus', $ticket);
        $updates = $user->can('update', $ticket) && $ticket->status !== TicketStatus::Closed;

        return view('tickets::livewire.ticket-show', [
            'organization' => $context->get(),
            'ticket' => $ticket,
            'row' => $row,
            'history' => $history->of($ticket),
            'canReview' => $manages && $ticket->status->canMoveTo(TicketStatus::UnderReview),
            'canStart' => $manages && $ticket->status->canMoveTo(TicketStatus::InProgress),
            'canResolve' => $manages && $ticket->status->canMoveTo(TicketStatus::Resolved),
            'canClose' => $manages && $ticket->status->canMoveTo(TicketStatus::Closed),
            'canReopen' => $manages && $ticket->status->canMoveTo(TicketStatus::Reopened),
            'canUpdate' => $updates,
            'assignees' => $updates ? $assignees->for($ticket->property_id) : [],
            'categories' => TicketCategory::cases(),
            'priorities' => TicketPriority::cases(),
            'reopenableUntil' => $tickets->reopenableUntil($ticket),
            'flash' => session('ticket-status'),
        ]);
    }

    /** The ticket as stored now, checked for the ability. */
    private function authorized(string $ability): Ticket
    {
        $ticket = $this->ticket();
        $this->authorize($ability, $ticket);

        return $ticket;
    }

    private function done(string $notice): void
    {
        $this->reset('note', 'reason');
        $this->resetValidation();
        $this->fillFrom($this->ticket());
        $this->notice = $notice;
        // The conversation shows whether it is still open (P04-02).
        $this->dispatch('ticket-changed');
    }

    private function fillFrom(Ticket $ticket): void
    {
        $this->assigneeId = (string) ($ticket->assigned_to_user_id ?? '');
        $this->category = $ticket->category->value;
        $this->priority = $ticket->priority->value;
    }

    private function ticket(): Ticket
    {
        return Ticket::query()->findOrFail($this->ticketId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
