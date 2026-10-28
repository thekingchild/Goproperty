<?php

declare(strict_types=1);

namespace Modules\Tickets\Listeners;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Tickets\Enums\MessageAuthor;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Events\TicketAssignmentChanged;
use Modules\Tickets\Events\TicketMessagePosted;
use Modules\Tickets\Events\TicketMovedIn;
use Modules\Tickets\Events\TicketStatusChanged;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Models\TicketMessage;
use Modules\Tickets\Notices\AssignmentNoticeText;
use Modules\Tickets\Notices\MessageNoticeText;
use Modules\Tickets\Notices\MoveNoticeText;
use Modules\Tickets\Notices\StatusNoticeText;
use Modules\Tickets\Notices\TicketNotifier;
use Modules\Tickets\Notices\TicketRecipients;

/**
 * Notifications on every transition (P04-03; ARCHITECTURE.md § 6.6;
 * docs/plans/P04-subtasks.md default 9): each party once, never the
 * person who acted.
 *
 * - A move (TicketStatusChanged): the ticket's tenant (unless they made
 *   it) and the staff side as it was at the move — the assignee, else the
 *   owners and administrators who may see the ticket (Notices\
 *   TicketRecipients). The daily auto-close (actor `system`) tells both.
 *   A staff ticket created already assigned tells its assignee through
 *   the assignment that comes with it, not twice.
 * - An assignment (TicketAssignmentChanged): the new assignee, unless they
 *   gave it to themselves; taking a ticket off someone (unassigned, or a
 *   member who left) tells nobody.
 *
 * - A message of the thread (TicketMessagePosted, P04-02): a reply by the
 *   tenant or the building's owner tells the staff side (the assignee at
 *   the time, else owners and administrators); a member's reply tells the
 *   tenant and the staff side; a member's **internal note tells the staff
 *   side only** — never the tenant (nor the owner), and its words are
 *   never quoted. Never the author. Property owners are not told in P04.
 *   Keyed by the message's id (`message:{id}`).
 *
 * - A ticket that arrived with its building on a handover (TicketMovedIn,
 *   P04-05): its tenant ("your repair request is now handled by …") and
 *   the receiving organization's owners and administrators, once each per
 *   ticket (nobody is assigned yet). The ticket left behind closes with
 *   the actor `move` and tells nobody there, so the tenant hears once.
 *
 * Drafts send no events, so nobody hears of them. Queued on
 * `notifications` (the events fire after the commit); a retried job tells
 * nobody twice (Notices\TicketNotifier keys each announcement by the
 * event's `key`).
 */
final class TellTicketParties implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 5;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly TicketRecipients $recipients,
        private readonly TicketNotifier $notifier,
    ) {}

    /** @return list<int> seconds before each retry */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(TicketStatusChanged|TicketAssignmentChanged|TicketMessagePosted|TicketMovedIn $event): void
    {
        $organization = Organization::query()->find($event->organizationId);

        if ($organization === null) {
            return;
        }

        $this->context->run($organization, function () use ($event): void {
            $ticket = Ticket::query()->find($event->ticketId);

            if (! $ticket instanceof Ticket || $ticket->status === TicketStatus::Draft) {
                return;
            }

            match (true) {
                $event instanceof TicketStatusChanged => $this->moved($ticket, $event),
                $event instanceof TicketAssignmentChanged => $this->assigned($ticket, $event),
                $event instanceof TicketMessagePosted => $this->posted($ticket, $event),
                $event instanceof TicketMovedIn => $this->movedIn($ticket, $event),
            };
        });
    }

    private function moved(Ticket $ticket, TicketStatusChanged $event): void
    {
        if ($event->actor === TicketStatusChanged::ACTOR_MOVE) {
            // Left behind when its building moved: the organization it moved to tells the tenant (movedIn).
            return;
        }

        $tenant = $this->recipients->tenant($ticket, $event->actorUserId);
        // Created already assigned: the assignment's own notice tells the assignee.
        $staff = $event->from === null && $event->assignedToUserId !== null
            ? []
            : $this->recipients->staff($ticket, $event->assignedToUserId, $event->actorUserId);

        $this->notifier->tell($ticket, 'status:'.$event->key, [...($tenant !== null ? [$tenant] : []), ...$staff], StatusNoticeText::of($event), $event->actorUserId);
    }

    private function movedIn(Ticket $ticket, TicketMovedIn $event): void
    {
        $tenant = $this->recipients->tenant($ticket, null);
        $staff = $this->recipients->staff($ticket, null, null);

        $this->notifier->tell($ticket, 'moved:'.$event->key, [...($tenant !== null ? [$tenant] : []), ...$staff], MoveNoticeText::of($event));
    }

    private function assigned(Ticket $ticket, TicketAssignmentChanged $event): void
    {
        if ($event->assignedToUserId === null) {
            return;
        }

        $assignee = $this->recipients->member($ticket, $event->assignedToUserId, $event->actorUserId);

        if ($assignee !== null) {
            $this->notifier->tell($ticket, 'assignment:'.$event->key, [$assignee], new AssignmentNoticeText, $event->actorUserId);
        }
    }

    private function posted(Ticket $ticket, TicketMessagePosted $event): void
    {
        $author = MessageAuthor::from($event->authorKind);
        // Only a member's public reply goes to the tenant: never a note, never their own words back.
        $tenant = ! $event->internal && $author === MessageAuthor::Staff ? $this->recipients->tenant($ticket, $event->authorUserId) : null;
        $staff = $this->recipients->staff($ticket, $event->assignedToUserId, $event->authorUserId);
        $message = $event->internal ? null : TicketMessage::query()->find($event->messageId);

        $this->notifier->tell(
            $ticket,
            'message:'.$event->messageId,
            [...($tenant !== null ? [$tenant] : []), ...$staff],
            new MessageNoticeText($author, $event->internal, MessageNoticeText::excerptOf($message?->body, $event->internal), $event->attachmentCount),
            $event->authorUserId,
        );
    }
}
