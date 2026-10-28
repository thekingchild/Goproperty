<?php

declare(strict_types=1);

namespace Modules\Tickets\Livewire;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Livewire\Concerns\WritesTicketMessages;
use Modules\Tickets\Messages\ThreadViewer;
use Modules\Tickets\Messages\TicketMessages;
use Modules\Tickets\Messages\TicketThread;
use Modules\Tickets\Models\Ticket;

/**
 * A ticket's conversation on its workspace page (P04-02; the "Conversation"
 * slot of tickets::livewire.ticket-show): the thread with the tenant and
 * the owner, the members' internal notes among it (marked "Internal note:
 * staff only"), and the reply form — the words, "Internal note" for
 * members who add them, photos and PDFs. Sending goes through
 * Messages\TicketMessages, which checks TicketPolicy (`reply`,
 * `addInternalNote`, `attachFiles`) and the ticket's state again; a closed
 * ticket's conversation has ended. Refreshes when the page's actions move
 * the ticket (`ticket-changed`).
 *
 * The ticket id is locked and the ticket loaded through the organization
 * scope and checked (`view`) on every request, as on the page.
 */
final class TicketConversation extends Component
{
    use WritesTicketMessages;

    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $ticketId;

    /** Send as an internal note (staff only). */
    public bool $internal = false;

    #[Locked]
    public ?string $notice = null;

    /** @var array<string, string> */
    protected $listeners = ['ticket-changed' => '$refresh'];

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, int $ticketId): void
    {
        $this->organizationId = $context->id();
        $this->ticketId = $ticketId;
        $this->authorize('view', $this->ticket());
    }

    public function send(TicketMessages $messages): void
    {
        $user = $this->user();
        $ticket = $this->ticket();
        $this->authorize('view', $ticket);
        $this->throttle($user);

        $messages->reply($user, $ticket, $this->body, $this->chosenFiles(), $this->internal);

        $this->notice = $this->internal ? __('Internal note added. Only staff see it.') : __('Message sent.');
        $this->sent();
        $this->internal = false;
    }

    public function render(TicketThread $thread, OrganizationContext $context): View
    {
        $ticket = $this->ticket();
        // Checked on every render, not only when the page opened.
        $this->authorize('view', $ticket);
        $user = $this->user();
        $open = ! in_array($ticket->status, [TicketStatus::Draft, TicketStatus::Closed], true);
        $files = $user->can('downloadAttachment', $ticket);
        $organization = $context->get();

        return view('tickets::livewire.ticket-conversation', [
            'messages' => $thread->of($ticket, ThreadViewer::Staff, $user->id),
            'closed' => $ticket->status === TicketStatus::Closed,
            'canReply' => $open && $user->can('reply', $ticket),
            'canNote' => $open && $user->can('addInternalNote', $ticket),
            'canAttach' => $open && $user->can('attachFiles', $ticket),
            'fileUrl' => static fn (int $media, bool $thumb): ?string => $files
                ? route($thumb ? 'workspace.tickets.files.thumb' : 'workspace.tickets.files.show', ['organization' => $organization, 'ticket' => $ticket->id, 'media' => $media])
                : null,
        ]);
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
