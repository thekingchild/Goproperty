<?php

declare(strict_types=1);

namespace Modules\Tickets\Livewire\Portal;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Tenants\Contracts\PortalTenant;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Livewire\Concerns\WritesTicketMessages;
use Modules\Tickets\Messages\ThreadViewer;
use Modules\Tickets\Messages\TicketMessages;
use Modules\Tickets\Messages\TicketThread;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Portal\TenantTickets;

/**
 * The conversation with the agency on one of my repairs (P04-02; the
 * "Conversation" slot of tickets::livewire.portal.ticket-show): the
 * thread — never the agency's internal notes: Messages\TicketThread leaves
 * them out of the query for the tenant, so they are in no page, payload or
 * file link — and the reply form (words, photos, PDFs) while the ticket is
 * open and the agency's account is not on hold. Sending goes through
 * Messages\TicketMessages::replyAsTenant inside the agency's organization,
 * which checks TicketPolicy::replyAsTenant (and `attachFiles`) and the
 * ticket's state again. The ticket is looked up among the user's own on
 * every request (Portal\TenantTickets): anyone else's answers 404.
 * Phone first: one column, 44 px targets, no autofocus.
 */
final class PortalTicketConversation extends Component
{
    use WritesTicketMessages;

    #[Locked]
    public int $ticketId;

    #[Locked]
    public ?string $notice = null;

    /** @var array<string, string> */
    protected $listeners = ['ticket-changed' => '$refresh'];

    public function mount(TenantTickets $tickets, int $ticketId): void
    {
        $this->ticketId = $ticketId;
        $this->found($tickets);
    }

    public function send(TenantTickets $tickets, TicketMessages $messages): void
    {
        $user = $this->user();
        [$tenancy, $ticket] = $this->found($tickets);
        $this->throttle($user);

        $tickets->within($tenancy, fn () => $messages->replyAsTenant($user, $ticket, $this->body, $this->chosenFiles()));

        $this->notice = __('Message sent.');
        $this->sent();
    }

    public function render(TenantTickets $tickets, TicketThread $thread): View
    {
        $user = $this->user();
        [$tenancy, $ticket] = $this->found($tickets);

        $state = $tickets->within($tenancy, static function () use ($user, $ticket, $thread, $tenancy): array {
            $open = ! in_array($ticket->status, [TicketStatus::Draft, TicketStatus::Closed], true) && ! $tenancy->readOnly();
            $files = $user->can('downloadAttachmentAsTenant', $ticket);

            return [
                'messages' => $ticket->status === TicketStatus::Draft ? [] : $thread->of($ticket, ThreadViewer::Tenant, $user->id),
                'canReply' => $open && $user->can('replyAsTenant', $ticket),
                'canAttach' => $open && $user->can('attachFiles', $ticket),
                'fileUrl' => static fn (int $media, bool $thumb): ?string => $files
                    ? route($thumb ? 'portal.tickets.files.thumb' : 'portal.tickets.files.show', ['ticket' => $ticket->id, 'media' => $media])
                    : null,
            ];
        });

        return view('tickets::livewire.portal.ticket-conversation', $state + [
            'draft' => $ticket->status === TicketStatus::Draft,
            'closed' => $ticket->status === TicketStatus::Closed,
            'readOnly' => $tenancy->readOnly(),
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
