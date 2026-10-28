<?php

declare(strict_types=1);

namespace Modules\Tickets\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\Response;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Tenants\Contracts\TenantPortalAccess;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Owner\OwnerTickets;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Maintenance tickets in the current organization (ARCHITECTURE.md § 5,
 * § 6.6; P04-01). Who, never which move: the state machine is
 * Tickets\TicketService's (a move it does not allow is TicketRefused).
 *
 * Members (the workspace):
 * - `tickets.view` (org-owner, org-admin, agent; kept while suspended)
 *   sees the sent tickets and exports the list;
 * - `tickets.create` (the same three) raises a ticket on a tenant's
 *   behalf, or for a unit with no tenant;
 * - `tickets.manage` (the same three) moves tickets through their states
 *   (review, work, resolve, close on the tenant's word, reopen at any
 *   time), assigns them and changes their category and priority.
 * `tickets.respond` (replies, internal notes) is P04-02's, below. A tenant's unsent draft is
 * nobody else's: it answers 404 to members. Property owners (`/owner`,
 * P04-02, below) and tenants hold no workspace role here.
 *
 * Tenants (the portal; they hold no workspace role, P03 decision 4, so
 * their identity is the permission, as for payment proofs): a ticket is
 * theirs when its tenant record is one of the user's own here
 * (Modules\Tenants\Contracts\TenantPortalAccess: linked to the user, not
 * archived, and while staff impersonate only the session's organization).
 * They raise tickets for the homes they hold a live lease on (the service
 * checks the lease), keep and send drafts, confirm a resolution and reopen
 * (within `tickets.reopen_days`, the service checks); anything else is 404.
 *
 * The central guards apply on top: in a suspended or cancelled
 * organization only the reads pass (OrganizationWriteGuard: no ticket is
 * raised, moved or assigned, by members or tenants, until it is
 * reactivated); while GoProperty staff impersonate, a read-only session
 * changes nothing and a write session may raise and move tickets, audited
 * with both identities (no ticket permission is blocked: tickets carry no
 * money, credentials or contact details; ImpersonationGuard).
 *
 * Agent narrowing (P02-03): an agent assigned to buildings sees and works
 * on those buildings' tickets only; any other ticket answers 404
 * (Response::denyAsNotFound), checked before the ability's own rule.
 *
 * The thread (P04-02, Messages\TicketMessages): members holding
 * `tickets.respond` reply and add internal notes (`reply`,
 * `addInternalNote`); whoever sees the ticket in the workspace sees the
 * notes (`viewInternalNotes`); the tenant replies to their own ticket
 * (`replyAsTenant`); the building's owner (`/owner`; their identity is
 * the permission, as on the owner portal's other pages: the ticket's
 * building is one of the user's owner profiles' here, Owner\OwnerTickets)
 * sees the ticket (`viewAsOwner`) and replies only while no agent is
 * assigned (`replyAsOwner`, ARCHITECTURE.md § 5). Tenants and owners never
 * see internal notes: no ability lets them (Messages\TicketThread leaves
 * the notes out of their queries). Files: adding them (`attachFiles`) and
 * opening them (`downloadAttachment…`) are refused while GoProperty staff
 * impersonate (evidence and people's photos: like every other uploaded
 * file, PLATFORM_CONSOLE.md § 6); the words are not.
 */
final class TicketPolicy
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly BuildingAccess $access,
        private readonly TenantPortalAccess $portal,
        private readonly OwnerTickets $owners,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewTickets);
    }

    /** Export the tickets list (Lists\TicketList, already narrowed; tenants by name only). */
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Ticket $ticket): Response|bool
    {
        return $this->reachable($user, $ticket) ?? $this->viewAny($user);
    }

    /** Raise a ticket on a tenant's behalf or for a unit with no tenant (which unit: the service checks the member's buildings). */
    public function create(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::CreateTickets);
    }

    /** Move the ticket: start review or work, resolve, close, reopen. */
    public function changeStatus(User $user, Ticket $ticket): Response|bool
    {
        return $this->reachable($user, $ticket) ?? $this->holds($user, OrganizationPermission::ManageTickets);
    }

    /** Assign the ticket, change its category or priority. */
    public function update(User $user, Ticket $ticket): Response|bool
    {
        return $this->reachable($user, $ticket) ?? $this->holds($user, OrganizationPermission::ManageTickets);
    }

    /** Never: tickets are history (AGENT.md § 3 rule 7); only a tenant's own draft is discarded (updateAsTenant). */
    public function delete(User $user, Ticket $ticket): bool
    {
        return false;
    }

    public function forceDelete(User $user, Ticket $ticket): bool
    {
        return false;
    }

    /** The tenant's own ticket, from the portal. */
    public function viewAsTenant(User $user, Ticket $ticket): Response|bool
    {
        return $this->ownedBy($user, $ticket) ? true : Response::denyAsNotFound();
    }

    /** Raise a ticket from the portal (which home: the service checks the user's live leases here). */
    public function createAsTenant(User $user): bool
    {
        return $this->context->has() && $this->portal->profileIdsHere($user) !== [];
    }

    /** Change, send or discard their own draft (only a draft: the service checks the state). */
    public function updateAsTenant(User $user, Ticket $ticket): Response|bool
    {
        return $this->ownedBy($user, $ticket) ? true : Response::denyAsNotFound();
    }

    /** Confirm the resolution of their own ticket (it closes). */
    public function closeAsTenant(User $user, Ticket $ticket): Response|bool
    {
        return $this->ownedBy($user, $ticket) ? true : Response::denyAsNotFound();
    }

    /** Reopen their own resolved or closed ticket (within the window: the service checks). */
    public function reopenAsTenant(User $user, Ticket $ticket): Response|bool
    {
        return $this->ownedBy($user, $ticket) ? true : Response::denyAsNotFound();
    }

    /** Reply in the thread: the tenant and the building's owner see it (P04-02). */
    public function reply(User $user, Ticket $ticket): Response|bool
    {
        return $this->reachable($user, $ticket) ?? $this->holds($user, OrganizationPermission::RespondToTickets);
    }

    /** Add an internal note: only staff ever see it. */
    public function addInternalNote(User $user, Ticket $ticket): Response|bool
    {
        return $this->reachable($user, $ticket) ?? $this->holds($user, OrganizationPermission::RespondToTickets);
    }

    /** See the internal notes: whoever sees the ticket in the workspace. */
    public function viewInternalNotes(User $user, Ticket $ticket): Response|bool
    {
        return $this->view($user, $ticket);
    }

    /** Open a file of the thread from the workspace (internal notes' too). */
    #[BlockedWhileImpersonating]
    public function downloadAttachment(User $user, Ticket $ticket): Response|bool
    {
        return $this->view($user, $ticket);
    }

    /** The tenant replies to their own ticket from the portal. */
    public function replyAsTenant(User $user, Ticket $ticket): Response|bool
    {
        return $this->ownedBy($user, $ticket) ? true : Response::denyAsNotFound();
    }

    /** The tenant opens a file of their ticket's thread (never an internal note's: TicketThread). */
    #[BlockedWhileImpersonating]
    public function downloadAttachmentAsTenant(User $user, Ticket $ticket): Response|bool
    {
        return $this->ownedBy($user, $ticket) ? true : Response::denyAsNotFound();
    }

    /** The building's owner sees a sent ticket of their building, from /owner. */
    public function viewAsOwner(User $user, Ticket $ticket): Response|bool
    {
        return $this->ownerSees($user, $ticket) ? true : Response::denyAsNotFound();
    }

    /** The building's owner replies, only while no agent is assigned (ARCHITECTURE.md § 5; the writer checks again on the locked row). */
    public function replyAsOwner(User $user, Ticket $ticket): Response|bool
    {
        if (! $this->ownerSees($user, $ticket)) {
            return Response::denyAsNotFound();
        }

        return $ticket->assigned_to_user_id === null
            ? true
            : Response::deny(__('An agent of the agency is handling this ticket now, so the conversation is theirs.'));
    }

    /** The building's owner opens a file of the thread (never an internal note's: TicketThread). */
    #[BlockedWhileImpersonating]
    public function downloadAttachmentAsOwner(User $user, Ticket $ticket): Response|bool
    {
        return $this->ownerSees($user, $ticket) ? true : Response::denyAsNotFound();
    }

    /** Add files to a message: whoever may write in the thread, never while staff impersonate (evidence). */
    #[BlockedWhileImpersonating]
    public function attachFiles(User $user, Ticket $ticket): Response|bool
    {
        if ($this->ownedBy($user, $ticket)) {
            return true;
        }

        $staff = $this->reply($user, $ticket);

        if ($staff === true || ($staff instanceof Response && $staff->allowed())) {
            return true;
        }

        return $this->replyAsOwner($user, $ticket);
    }

    /**
     * Null when the ticket is this organization's, sent, and in the
     * member's buildings (the ability's own rule decides); false for
     * another organization's ticket; 404 for a tenant's draft and outside a
     * narrowed agent's buildings.
     */
    private function reachable(User $user, Ticket $ticket): Response|false|null
    {
        if (! $this->context->has() || $ticket->organization_id !== $this->context->id()) {
            return false;
        }

        if ($ticket->status === TicketStatus::Draft || ! $this->access->allows($user, $ticket->property_id)) {
            return Response::denyAsNotFound();
        }

        return null;
    }

    private function ownedBy(User $user, Ticket $ticket): bool
    {
        return $this->context->has()
            && $ticket->organization_id === $this->context->id()
            && $ticket->tenant_profile_id !== null
            && in_array($ticket->tenant_profile_id, $this->portal->profileIdsHere($user), true);
    }

    private function ownerSees(User $user, Ticket $ticket): bool
    {
        return $this->context->has()
            && $ticket->organization_id === $this->context->id()
            && $ticket->status !== TicketStatus::Draft
            && $this->owners->ownerProfileFor($user, $ticket) !== null;
    }

    private function holds(User $user, OrganizationPermission $permission): bool
    {
        if (! $this->context->has() || ! in_array($this->context->id(), $user->workspaceOrganizationIds(), true)) {
            return false;
        }

        try {
            return $user->hasPermissionTo($permission->value, OrganizationPermission::GUARD);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
