<?php

declare(strict_types=1);

namespace Modules\Tickets\Livewire\Owner;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Properties\Contracts\PortalOwner;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Livewire\Concerns\WritesTicketMessages;
use Modules\Tickets\Messages\ThreadViewer;
use Modules\Tickets\Messages\TicketMessages;
use Modules\Tickets\Messages\TicketThread;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Owner\OwnerTickets;

/**
 * The conversation on a ticket of one of my buildings, in /owner (P04-02;
 * ARCHITECTURE.md § 5 "respond to tickets if no agent assigned"): the
 * thread — never the agency's internal notes (Messages\TicketThread leaves
 * them out of the owner's query) — and, only while no agent is assigned,
 * the ticket is open and the agency's account is not on hold, the reply
 * form. Sending goes through Messages\TicketMessages::replyAsOwner, which
 * checks TicketPolicy::replyAsOwner and, on the locked ticket, that nobody
 * was assigned meanwhile: an assigned ticket's reply is refused with
 * nothing written, whatever the page showed. The owner portal's only
 * component that writes (its pages are ReadOnlyOwnerPage); the ticket is
 * found among the user's buildings on every request (Owner\OwnerTickets),
 * so anyone else's answers 404.
 */
final class OwnerTicketConversation extends Component
{
    use WritesTicketMessages;

    #[Locked]
    public int $ticketId;

    #[Locked]
    public ?string $notice = null;

    public function mount(OwnerTickets $tickets, int $ticketId): void
    {
        $this->ticketId = $ticketId;
        $this->found($tickets);
    }

    public function send(OwnerTickets $tickets, TicketMessages $messages): void
    {
        $user = $this->user();
        [$owner, $ticket] = $this->found($tickets);
        $this->throttle($user);

        $tickets->within($user, $owner, fn () => $messages->replyAsOwner($user, $ticket, $this->body, $this->chosenFiles()));

        $this->notice = __('Message sent.');
        $this->sent();
    }

    public function render(OwnerTickets $tickets, TicketThread $thread, OrganizationContext $context): View
    {
        $user = $this->user();
        [$owner, $ticket] = $this->found($tickets);

        $state = $tickets->within($user, $owner, static function () use ($user, $ticket, $thread, $context): array {
            $closed = $ticket->status === TicketStatus::Closed;
            $onHold = $context->get()->status->blocksWrites();
            $files = $user->can('downloadAttachmentAsOwner', $ticket);
            $canReply = ! $closed && ! $onHold && $user->can('replyAsOwner', $ticket);

            return [
                'messages' => $thread->of($ticket, ThreadViewer::Owner, $user->id),
                'closed' => $closed,
                'onHold' => $onHold,
                'assigned' => $ticket->assigned_to_user_id !== null,
                'canReply' => $canReply,
                'canAttach' => $canReply && $user->can('attachFiles', $ticket),
                'fileUrl' => static fn (int $media, bool $thumb): ?string => $files
                    ? route($thumb ? 'owner.tickets.files.thumb' : 'owner.tickets.files.show', ['ticket' => $ticket->id, 'media' => $media])
                    : null,
            ];
        });
        abort_if($state === null, 404);

        return view('tickets::livewire.owner.ticket-conversation', $state);
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
