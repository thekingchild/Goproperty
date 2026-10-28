<?php

declare(strict_types=1);

namespace Modules\Tickets\Notices;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Members\Members;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\Tenants\Contracts\TenantDirectory;
use Modules\Tickets\Models\Ticket;

/**
 * Who is told about something that happened on a ticket
 * (docs/plans/P04-subtasks.md default 9; ARCHITECTURE.md § 6.6
 * "Notifications"), each once and never the person who did it:
 *
 * - the **tenant** the ticket is about, when it has one who can hear: a
 *   tenant record that is not archived, with an email address (their
 *   portal account's, else the record's). They are told in the app when
 *   they have a portal account, and by email. A phone-only tenant is told
 *   nothing until P05 (WhatsApp/SMS);
 * - the **staff side**: the member the ticket is assigned to, when they
 *   may still see it (TicketPolicy::view: a member holding `tickets.view`
 *   whose building narrowing allows the building); else — unassigned, or
 *   the assignee can no longer see it — the organization's owners and
 *   administrators who may see it. An assignee who is the actor means
 *   nobody on the staff side (it is their own move).
 *
 * Property owners (landlords) are not told in P04. Runs in the ticket's
 * organization (the current context). Used by Listeners\TellTicketParties
 * for status moves, assignments and the thread's messages (an internal
 * note: the staff side only, never tenant()).
 */
final readonly class TicketRecipients
{
    public function __construct(
        private Members $members,
        private TenantDirectory $tenants,
    ) {}

    /**
     * The tenant and the staff side of the ticket as it is now, without
     * the actor and without repeats.
     *
     * @return list<TicketRecipient>
     */
    public function of(Ticket $ticket, ?int $actorUserId, bool $tenant = true, bool $staff = true): array
    {
        return self::once([
            ...($tenant && ($recipient = $this->tenant($ticket, $actorUserId)) !== null ? [$recipient] : []),
            ...($staff ? $this->staff($ticket, $ticket->assigned_to_user_id, $actorUserId) : []),
        ]);
    }

    /** The ticket's tenant, when it has one who can hear and who is not the actor. */
    public function tenant(Ticket $ticket, ?int $actorUserId): ?TicketRecipient
    {
        if ($ticket->tenant_profile_id === null) {
            return null;
        }

        $tenant = $this->tenants->find($ticket->tenant_profile_id);

        if ($tenant === null || $tenant->archived) {
            return null;
        }

        $user = $tenant->userId !== null ? User::query()->find($tenant->userId) : null;

        if ($actorUserId !== null && $user?->id === $actorUserId) {
            return null;
        }

        $mailTo = self::address($user?->email) ?? self::address($tenant->email);

        if ($mailTo === null) {
            // Phone-only: nothing until P05 brings WhatsApp and SMS (default 9).
            return null;
        }

        return new TicketRecipient(
            side: TicketRecipient::TENANT,
            recipient: $user !== null ? 'user:'.$user->id : 'tenant:'.$tenant->id,
            name: $tenant->name,
            user: $user,
            mailTo: $mailTo,
        );
    }

    /**
     * The staff side for a ticket assigned to $assigneeId (null:
     * unassigned): that member, else the owners and administrators who
     * may see the ticket; never the actor.
     *
     * @return list<TicketRecipient>
     */
    public function staff(Ticket $ticket, ?int $assigneeId, ?int $actorUserId): array
    {
        $assignee = $assigneeId !== null ? User::query()->find($assigneeId) : null;

        if ($assignee instanceof User && $this->maySee($assignee, $ticket)) {
            return $assignee->id === $actorUserId ? [] : [TicketRecipient::staff($assignee)];
        }

        $ids = array_values(array_diff($this->members->holdersOf(OrganizationPermission::ViewTickets), $actorUserId !== null ? [$actorUserId] : []));

        if ($ids === []) {
            return [];
        }

        return array_values(User::query()->whereKey($ids)->orderBy('id')->get()
            ->filter(fn (User $user): bool => $user->hasAnyRole(OrganizationRole::Owner->value, OrganizationRole::Admin->value) && $this->maySee($user, $ticket))
            ->map(static fn (User $user): TicketRecipient => TicketRecipient::staff($user))
            ->all());
    }

    /** One member — a new assignee — when they may see the ticket and are not the actor. */
    public function member(Ticket $ticket, int $userId, ?int $actorUserId): ?TicketRecipient
    {
        if ($userId === $actorUserId) {
            return null;
        }

        $user = User::query()->find($userId);

        return $user instanceof User && $this->maySee($user, $ticket) ? TicketRecipient::staff($user) : null;
    }

    /**
     * @param  list<TicketRecipient>  $recipients
     * @return list<TicketRecipient> the first of each recipient
     */
    public static function once(array $recipients): array
    {
        $seen = [];

        return array_values(array_filter($recipients, static function (TicketRecipient $recipient) use (&$seen): bool {
            if (isset($seen[$recipient->recipient])) {
                return false;
            }

            return $seen[$recipient->recipient] = true;
        }));
    }

    private function maySee(User $user, Ticket $ticket): bool
    {
        return Gate::forUser($user)->allows('view', $ticket);
    }

    private static function address(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email === '' ? null : $email;
    }
}
