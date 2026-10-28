<?php

declare(strict_types=1);

namespace Modules\Tickets\Tickets;

use App\Documents\DocumentNumberer;
use App\Documents\DocumentType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Leasing\Contracts\LeaseSummary;
use Modules\Leasing\Contracts\TenantLeases;
use Modules\Leasing\Contracts\UnitLeases;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Tenants\Contracts\TenantPortalAccess;
use Modules\Tickets\Contracts\TicketService as TicketServiceContract;
use Modules\Tickets\Contracts\TicketSummary;
use Modules\Tickets\Enums\TicketCategory;
use Modules\Tickets\Enums\TicketPriority;
use Modules\Tickets\Enums\TicketSource;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Events\TicketAssignmentChanged;
use Modules\Tickets\Events\TicketStatusChanged;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Models\TicketStatusChange;

/**
 * The only writer of maintenance tickets and their status history
 * (AGENT.md § 3 rule 5; ARCHITECTURE.md § 6.6; DATABASE_SCHEMA.md § 5).
 * Every move of the state machine (TicketStatus) happens here, inside a
 * transaction run as DB::transaction($callback, 3), with the ticket's row
 * locked (SELECT … FOR UPDATE) and re-read: a move the state machine does
 * not allow is refused (TicketRefused) and changes nothing. Each move
 * writes one `ticket_status_history` row and one audit entry (log
 * `tickets`, the ticket as subject), and sends TicketStatusChanged after
 * the commit (P04-03's notifications listen there).
 *
 * - Tenants (the portal, TicketPolicy's …AsTenant abilities): draftAsTenant()
 *   for a home they hold a live (draft or active) lease on, updateDraft(),
 *   discardDraft() (a soft delete: the draft was never sent), submit()
 *   (the ticket gets its number), confirmResolution() (closes a resolved
 *   ticket) and reopenAsTenant() within `tickets.reopen_days` of the
 *   resolution (docs/plans/P04-subtasks.md default 1).
 * - Members (`tickets.create` / `tickets.manage`): createOnBehalf() (starts
 *   `submitted`, numbered, for a unit of their buildings and optionally its
 *   tenant), startReview(), startWork(), resolve(), close() (on the
 *   tenant's word), reopen() (any time), assign() (to a member who works on
 *   the building, Assignees), changeDetails() (category, priority).
 * - The system: autoClose() closes a resolved ticket the tenant has not
 *   answered for `tickets.auto_close_days` (tickets-auto-close, default 7;
 *   default 2), once; unassignMember() when a member leaves.
 *
 * Numbers come from App\Documents\DocumentNumberer (`TKT-000001`, one
 * sequence per organization, default 4) inside the sending transaction, so
 * they have no gaps. A ticket never changes `units.status` (default 6).
 *
 * Lock order, everywhere: the ticket row, then the number counter.
 */
final readonly class TicketService implements TicketServiceContract
{
    public const string LOG = 'tickets';

    public function __construct(
        private UnitDirectory $units,
        private BuildingAccess $access,
        private TenantLeases $tenantLeases,
        private UnitLeases $unitLeases,
        private TenantPortalAccess $portal,
        private DocumentNumberer $numberer,
        private Assignees $assignees,
        private TicketSettings $settings,
    ) {}

    /**
     * A tenant's new draft for one of their homes (input: category,
     * priority, title, description). Nobody else sees it until it is sent.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function draftAsTenant(User $by, int $leaseId, array $input): Ticket
    {
        Gate::forUser($by)->authorize('createAsTenant', Ticket::class);

        $values = TicketInput::validated($input, draft: true);
        $lease = $this->tenantHome($by, $leaseId);

        return $this->transaction(function () use ($by, $lease, $values): Ticket {
            $ticket = new Ticket;
            $ticket->property_id = $lease->propertyId;
            $ticket->unit_id = $lease->unitId;
            $ticket->lease_id = $lease->id;
            $ticket->tenant_profile_id = $lease->tenantProfileId;
            $ticket->source = TicketSource::Portal;
            $ticket->raised_by_user_id = $by->id;
            $ticket->status = TicketStatus::Draft;
            $this->fill($ticket, $values);
            $ticket->save();

            $this->history($ticket, null, $by, null);
            $this->audit('ticket.drafted', $ticket, $by, 'Ticket drafted', ['to' => TicketStatus::Draft->value]);

            return $ticket;
        });
    }

    /**
     * Change a draft (its home too).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function updateDraft(User $by, Ticket $ticket, int $leaseId, array $input): Ticket
    {
        Gate::forUser($by)->authorize('updateAsTenant', $ticket);

        $values = TicketInput::validated($input, draft: true);
        $lease = $this->tenantHome($by, $leaseId);

        return $this->transaction(function () use ($by, $ticket, $lease, $values): Ticket {
            $ticket = $this->lock($ticket->id);
            $this->refuseUnlessDraft($ticket);

            $ticket->property_id = $lease->propertyId;
            $ticket->unit_id = $lease->unitId;
            $ticket->lease_id = $lease->id;
            $ticket->tenant_profile_id = $lease->tenantProfileId;
            $this->fill($ticket, $values);
            $changed = array_keys($ticket->getDirty());

            if ($changed !== []) {
                $ticket->save();
                $this->audit('ticket.draft_updated', $ticket, $by, 'Draft ticket changed', ['changed' => $changed]);
            }

            return $ticket;
        });
    }

    /**
     * Throw a draft away (a soft delete: it was never sent, so nothing is
     * lost from anyone's history).
     *
     * @throws ValidationException
     */
    public function discardDraft(User $by, Ticket $ticket): void
    {
        Gate::forUser($by)->authorize('updateAsTenant', $ticket);

        $this->transaction(function () use ($by, $ticket): void {
            $ticket = $this->lock($ticket->id);
            $this->refuseUnlessDraft($ticket);

            $ticket->delete();
            $this->audit('ticket.draft_discarded', $ticket, $by, 'Draft ticket discarded');
        });
    }

    /**
     * Send a draft to the organization: it gets its number and waits there
     * (`submitted`). The home must still be the tenant's.
     *
     * @throws ValidationException
     */
    public function submit(User $by, Ticket $ticket): Ticket
    {
        Gate::forUser($by)->authorize('updateAsTenant', $ticket);

        return $this->move($by, $ticket, TicketStatus::Submitted, 'ticket.submitted', 'Ticket sent', null, TicketStatusChanged::ACTOR_TENANT, function (Ticket $ticket) use ($by): void {
            if ($ticket->description === null) {
                throw TicketRefused::because(__('Describe the problem before sending it.'), 'description');
            }

            if ($ticket->lease_id === null) {
                throw TicketRefused::because(__('Choose your home before sending it.'));
            }

            $this->tenantHome($by, $ticket->lease_id);
            $ticket->number = $this->numberer->next(DocumentType::Ticket);
        });
    }

    /**
     * A ticket a member raises (input: unitId, tenantId (optional: a
     * tenant of a live lease on the unit), category, priority, title,
     * description, assigneeId (optional)). It starts `submitted`, numbered.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function createOnBehalf(User $by, array $input): Ticket
    {
        Gate::forUser($by)->authorize('create', Ticket::class);

        $unitId = self::id($input['unitId'] ?? null);
        $unit = $unitId === null ? null : $this->units->find($unitId);

        try {
            $values = TicketInput::validated($input);
        } catch (ValidationException $invalid) {
            // Every field's problem at once, the unit's too.
            throw ValidationException::withMessages($invalid->errors() + ($unit === null ? ['unitId' => [__('Choose one of your units.')]] : []));
        }

        if ($unit === null) {
            throw ValidationException::withMessages(['unitId' => __('Choose one of your units.')]);
        }

        if (! $this->access->allows($by, $unit->propertyId)) {
            // As for another organization's unit: an assigned agent's other buildings answer 404.
            Response::denyAsNotFound()->authorize();
        }

        if ($unit->propertyArchived) {
            throw ValidationException::withMessages(['unitId' => __(':building is archived. Restore it before raising tickets for it.', ['building' => $unit->propertyName])]);
        }

        $lease = null;
        $tenantId = self::id($input['tenantId'] ?? null);

        if ($tenantId !== null) {
            $lease = array_values(array_filter($this->unitLeases->live($unit->id), static fn (LeaseSummary $lease): bool => $lease->tenantProfileId === $tenantId))[0]
                ?? throw ValidationException::withMessages(['tenantId' => __('Choose a tenant who rents :unit, or no tenant.', ['unit' => $unit->label])]);
        }

        $assigneeId = self::id($input['assigneeId'] ?? null);

        if ($assigneeId !== null && ! $this->assignees->allows($assigneeId, $unit->propertyId)) {
            throw ValidationException::withMessages(['assigneeId' => __('Choose a member who works on :building.', ['building' => $unit->propertyName])]);
        }

        return $this->transaction(function () use ($by, $unit, $lease, $values, $assigneeId): Ticket {
            $now = CarbonImmutable::now();
            $ticket = new Ticket;
            $ticket->property_id = $unit->propertyId;
            $ticket->unit_id = $unit->id;
            $ticket->lease_id = $lease?->id;
            $ticket->tenant_profile_id = $lease?->tenantProfileId;
            $ticket->source = TicketSource::Staff;
            $ticket->raised_by_user_id = $by->id;
            $ticket->status = TicketStatus::Submitted;
            $ticket->submitted_at = $now;
            $ticket->assigned_to_user_id = $assigneeId;
            $ticket->assigned_at = $assigneeId !== null ? $now : null;
            $this->fill($ticket, $values);
            $ticket->number = $this->numberer->next(DocumentType::Ticket);
            $ticket->save();

            $this->history($ticket, null, $by, null);
            $this->audit('ticket.raised', $ticket, $by, 'Ticket raised by staff', ['to' => TicketStatus::Submitted->value, 'assigned_to_user_id' => $assigneeId]);
            $this->changed($ticket, null, TicketStatusChanged::ACTOR_STAFF, $by, null);

            if ($assigneeId !== null) {
                event(new TicketAssignmentChanged($ticket->organization_id, $ticket->id, $ticket->number, null, $assigneeId, $by->id));
            }

            return $ticket;
        });
    }

    /** @throws ValidationException */
    public function startReview(User $by, Ticket $ticket): Ticket
    {
        Gate::forUser($by)->authorize('changeStatus', $ticket);

        return $this->move($by, $ticket, TicketStatus::UnderReview, 'ticket.review_started', 'Ticket under review', null, TicketStatusChanged::ACTOR_STAFF);
    }

    /** @throws ValidationException */
    public function startWork(User $by, Ticket $ticket): Ticket
    {
        Gate::forUser($by)->authorize('changeStatus', $ticket);

        return $this->move($by, $ticket, TicketStatus::InProgress, 'ticket.work_started', 'Work on the ticket started', null, TicketStatusChanged::ACTOR_STAFF);
    }

    /**
     * Mark it fixed, optionally saying what was done (the tenant sees the
     * note). The tenant may then confirm or reopen it.
     *
     * @throws ValidationException
     */
    public function resolve(User $by, Ticket $ticket, ?string $note = null): Ticket
    {
        Gate::forUser($by)->authorize('changeStatus', $ticket);
        $note = TicketInput::note($note);

        return $this->move($by, $ticket, TicketStatus::Resolved, 'ticket.resolved', 'Ticket resolved', $note, TicketStatusChanged::ACTOR_STAFF);
    }

    /**
     * A member closes a resolved ticket (the tenant confirmed it some other
     * way, or there is no tenant).
     *
     * @throws ValidationException
     */
    public function close(User $by, Ticket $ticket, ?string $note = null): Ticket
    {
        Gate::forUser($by)->authorize('changeStatus', $ticket);
        $note = TicketInput::note($note);

        return $this->move($by, $ticket, TicketStatus::Closed, 'ticket.closed', 'Ticket closed by staff', $note, TicketStatusChanged::ACTOR_STAFF);
    }

    /**
     * A member reopens a resolved or closed ticket, at any time, saying why.
     *
     * @throws ValidationException
     */
    public function reopen(User $by, Ticket $ticket, ?string $reason): Ticket
    {
        Gate::forUser($by)->authorize('changeStatus', $ticket);
        $reason = TicketInput::note($reason, required: true, key: 'reason', requiredMessage: __('Say why the ticket is reopened.'));

        return $this->move($by, $ticket, TicketStatus::Reopened, 'ticket.reopened', 'Ticket reopened by staff', $reason, TicketStatusChanged::ACTOR_STAFF);
    }

    /**
     * The tenant confirms the problem is fixed: the ticket closes.
     *
     * @throws ValidationException
     */
    public function confirmResolution(User $by, Ticket $ticket, ?string $note = null): Ticket
    {
        Gate::forUser($by)->authorize('closeAsTenant', $ticket);
        $note = TicketInput::note($note);

        return $this->move($by, $ticket, TicketStatus::Closed, 'ticket.closed', 'Resolution confirmed by the tenant', $note, TicketStatusChanged::ACTOR_TENANT);
    }

    /**
     * The tenant says it is not fixed: a resolved or closed ticket reopens,
     * until `tickets.reopen_days` after its resolution.
     *
     * @throws ValidationException
     */
    public function reopenAsTenant(User $by, Ticket $ticket, ?string $reason): Ticket
    {
        Gate::forUser($by)->authorize('reopenAsTenant', $ticket);
        $reason = TicketInput::note($reason, required: true, key: 'reason', requiredMessage: __('Say what is still wrong.'));

        return $this->move($by, $ticket, TicketStatus::Reopened, 'ticket.reopened', 'Ticket reopened by the tenant', $reason, TicketStatusChanged::ACTOR_TENANT, function (Ticket $ticket): void {
            $until = $this->reopenableUntil($ticket);

            if ($until === null || CarbonImmutable::now()->greaterThan($until)) {
                throw TicketRefused::because(__('This ticket could be reopened until :date. If the problem is back, report it again.', ['date' => TicketDates::day($until)]));
            }
        });
    }

    /**
     * The last moment the tenant may reopen the ticket: its resolution plus
     * the organization's `tickets.reopen_days`; null while it has never
     * been resolved, or is not resolved or closed now.
     */
    public function reopenableUntil(Ticket $ticket): ?CarbonImmutable
    {
        if (! in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true) || $ticket->resolved_at === null) {
            return null;
        }

        return $ticket->resolved_at->addDays($this->settings->reopenDays());
    }

    /**
     * tickets-auto-close: close a resolved ticket the tenant has not
     * answered for `tickets.auto_close_days`. False when there is nothing to
     * do (no longer resolved, or not old enough): safe to run again.
     */
    public function autoClose(int $ticketId): bool
    {
        return $this->transaction(function () use ($ticketId): bool {
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->first();
            $days = $this->settings->autoCloseDays();

            if (! $ticket instanceof Ticket || $ticket->status !== TicketStatus::Resolved || $ticket->resolved_at === null
                || $ticket->resolved_at->addDays($days)->greaterThan(CarbonImmutable::now())) {
                return false;
            }

            $note = trans_choice('Closed automatically: no answer :count day after it was resolved.|Closed automatically: no answer :count days after it was resolved.', $days, ['count' => $days]);
            $this->apply($ticket, TicketStatus::Closed, null, 'ticket.closed', 'Ticket closed automatically', $note, TicketStatusChanged::ACTOR_SYSTEM, ['auto_close_days' => $days]);

            return true;
        });
    }

    /**
     * Give the ticket to a member who works on its building, or to nobody
     * (null). Not a draft or a closed ticket.
     *
     * @throws ValidationException
     */
    public function assign(User $by, Ticket $ticket, ?int $userId): Ticket
    {
        Gate::forUser($by)->authorize('update', $ticket);

        return $this->transaction(function () use ($by, $ticket, $userId): Ticket {
            $ticket = $this->lock($ticket->id);
            $this->refuseUnlessWorkable($ticket);

            $previous = $ticket->assigned_to_user_id;

            if ($previous === $userId) {
                return $ticket;
            }

            if ($userId !== null && ! $this->assignees->allows($userId, $ticket->property_id)) {
                throw ValidationException::withMessages(['assigneeId' => __('Choose a member who works on this building.')]);
            }

            $ticket->assigned_to_user_id = $userId;
            $ticket->assigned_at = $userId !== null ? CarbonImmutable::now() : null;
            $ticket->save();

            $this->audit($userId !== null ? 'ticket.assigned' : 'ticket.unassigned', $ticket, $by, $userId !== null ? 'Ticket assigned' : 'Ticket unassigned', [
                'assigned_to_user_id' => $userId,
                'previous_user_id' => $previous,
            ]);
            event(new TicketAssignmentChanged($ticket->organization_id, $ticket->id, $ticket->number, $previous, $userId, $by->id));

            return $ticket;
        });
    }

    /**
     * Change the ticket's category and priority (input: category,
     * priority). Not a draft or a closed ticket.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function changeDetails(User $by, Ticket $ticket, array $input): Ticket
    {
        Gate::forUser($by)->authorize('update', $ticket);

        return $this->transaction(function () use ($by, $ticket, $input): Ticket {
            $ticket = $this->lock($ticket->id);
            $this->refuseUnlessWorkable($ticket);

            $values = TicketInput::validated(['title' => $ticket->title, 'description' => $ticket->description] + $input, draft: true);
            $before = ['category' => $ticket->category->value, 'priority' => $ticket->priority->value];
            $ticket->category = $values['category'];
            $ticket->priority = $values['priority'];
            $after = ['category' => $ticket->category->value, 'priority' => $ticket->priority->value];
            $changed = array_keys(array_diff_assoc($after, $before));

            if ($changed !== []) {
                $ticket->save();
                $this->audit('ticket.updated', $ticket, $by, 'Ticket details changed', [
                    'changed' => $changed,
                    'old' => array_intersect_key($before, array_flip($changed)),
                    'new' => array_intersect_key($after, array_flip($changed)),
                ]);
            }

            return $ticket;
        });
    }

    /**
     * A member left the organization (App\Members\MemberRemoved, inside the
     * removal's transaction): the tickets still waiting on them become
     * unassigned (owners and administrators see those). Closed tickets keep
     * who handled them.
     *
     * @return int the tickets unassigned
     */
    public function unassignMember(int $userId, ?int $byUserId): int
    {
        $ids = Ticket::query()->where('assigned_to_user_id', $userId)->where('status', '!=', TicketStatus::Closed)->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            $this->transaction(function () use ($id, $userId, $byUserId): void {
                $ticket = $this->lock((int) $id);

                if ($ticket->assigned_to_user_id !== $userId || $ticket->status === TicketStatus::Closed) {
                    return;
                }

                $ticket->assigned_to_user_id = null;
                $ticket->assigned_at = null;
                $ticket->save();

                $entry = activity(self::LOG)->event('ticket.unassigned')->performedOn($ticket)
                    ->withProperties(['number' => $ticket->number, 'previous_user_id' => $userId, 'reason' => 'member_removed']);

                if ($byUserId !== null && ($by = User::query()->find($byUserId)) instanceof User) {
                    $entry->causedBy($by);
                }

                $entry->log('Ticket unassigned: the member left the organization');
                event(new TicketAssignmentChanged($ticket->organization_id, $ticket->id, $ticket->number, $userId, null, null));
            });
        }

        return $ids->count();
    }

    public function find(int $ticketId, ?User $for = null): ?TicketSummary
    {
        $ticket = $this->sent($for)->find($ticketId);

        return $ticket instanceof Ticket ? self::summary($ticket) : null;
    }

    public function openCount(?User $for = null): int
    {
        return $this->sent($for)->whereIn('status', TicketStatus::open())->count();
    }

    public function forLease(int $leaseId, ?User $for = null): array
    {
        return array_values($this->sent($for)->where('lease_id', $leaseId)->orderByDesc('submitted_at')->orderByDesc('id')->get()
            ->map(self::summary(...))->all());
    }

    /**
     * The sent tickets of the current organization, narrowed for $for (and
     * none when $for may not see tickets at all).
     *
     * @return Builder<Ticket>
     */
    private function sent(?User $for): Builder
    {
        $query = Ticket::query()->where('status', '!=', TicketStatus::Draft);

        if ($for === null) {
            return $query;
        }

        if (! $for->can('viewAny', Ticket::class)) {
            return $query->whereRaw('1 = 0');
        }

        $limited = $this->access->limitedTo($for);

        return $query->when($limited !== null, static fn (Builder $query) => $query->whereIn('property_id', $limited ?? []));
    }

    /**
     * One move: lock and re-read the ticket, refuse what the state machine
     * does not allow, run $check (more refusals, or what the move fills
     * in), then apply it.
     *
     * @param  (Closure(Ticket): void)|null  $check
     *
     * @throws ValidationException
     */
    private function move(User $by, Ticket $ticket, TicketStatus $to, string $event, string $description, ?string $note, string $actor, ?Closure $check = null): Ticket
    {
        return $this->transaction(function () use ($by, $ticket, $to, $event, $description, $note, $actor, $check): Ticket {
            $ticket = $this->lock($ticket->id);

            if (! $ticket->status->canMoveTo($to)) {
                throw TicketRefused::because(__('A ticket that is :status cannot be :action.', [
                    'status' => mb_strtolower(__($ticket->status->label())),
                    'action' => self::action($to),
                ]));
            }

            if ($check !== null) {
                $check($ticket);
            }

            $this->apply($ticket, $to, $by, $event, $description, $note, $actor);

            return $ticket;
        });
    }

    /**
     * Applies an allowed move to the locked ticket: its status and dates,
     * the history row, the audit entry and the event.
     *
     * @param  array<string, mixed>  $properties
     */
    private function apply(Ticket $ticket, TicketStatus $to, ?User $by, string $event, string $description, ?string $note, string $actor, array $properties = []): void
    {
        $from = $ticket->status;
        $now = CarbonImmutable::now();
        $ticket->status = $to;

        if ($to === TicketStatus::Submitted) {
            $ticket->submitted_at = $now;
        } elseif ($to === TicketStatus::Resolved) {
            $ticket->resolved_at = $now;
        } elseif ($to === TicketStatus::Closed) {
            $ticket->closed_at = $now;
        } elseif ($to === TicketStatus::Reopened) {
            // The resolution stays: the reopen window and the next resolution start from it.
            $ticket->reopened_at = $now;
            $ticket->closed_at = null;
            $ticket->reopen_count++;
        }

        $ticket->save();

        $this->history($ticket, $from, $by, $note);
        $this->audit($event, $ticket, $by, $description, ['from' => $from->value, 'to' => $to->value, 'by' => $actor] + ($note !== null ? ['note' => $note] : []) + $properties);
        $this->changed($ticket, $from, $actor, $by, $note);
    }

    private function history(Ticket $ticket, ?TicketStatus $from, ?User $by, ?string $note): void
    {
        $change = new TicketStatusChange;
        $change->ticket_id = $ticket->id;
        $change->from_status = $from;
        $change->to_status = $ticket->status;
        $change->changed_by_user_id = $by?->id;
        $change->note = $note;
        $change->changed_at = CarbonImmutable::now();
        $change->save();
    }

    private function changed(Ticket $ticket, ?TicketStatus $from, string $actor, ?User $by, ?string $note): void
    {
        event(new TicketStatusChanged(
            organizationId: $ticket->organization_id,
            ticketId: $ticket->id,
            number: $ticket->number,
            from: $from?->value,
            to: $ticket->status->value,
            actor: $actor,
            actorUserId: $by?->id,
            propertyId: $ticket->property_id,
            unitId: $ticket->unit_id,
            tenantProfileId: $ticket->tenant_profile_id,
            assignedToUserId: $ticket->assigned_to_user_id,
            note: $note,
        ));
    }

    /** @param  array{category: TicketCategory, priority: TicketPriority, title: string, description: string|null}  $values */
    private function fill(Ticket $ticket, array $values): void
    {
        $ticket->category = $values['category'];
        $ticket->priority = $values['priority'];
        $ticket->title = $values['title'];
        $ticket->description = $values['description'];
    }

    /**
     * The tenant's live (draft or active) lease $leaseId here, on one of
     * the user's own tenant records; a validation error otherwise.
     *
     * @throws ValidationException
     */
    private function tenantHome(User $by, int $leaseId): LeaseSummary
    {
        foreach ($this->portal->profileIdsHere($by) as $profileId) {
            foreach ($this->tenantLeases->forTenant($profileId) as $lease) {
                if ($lease->id === $leaseId && in_array($lease->status, ['draft', 'active'], true)) {
                    if ($this->units->find($lease->unitId) === null) {
                        break;
                    }

                    return $lease;
                }
            }
        }

        throw ValidationException::withMessages(['home' => __('Choose one of the homes you rent.')]);
    }

    /** @throws TicketRefused */
    private function refuseUnlessDraft(Ticket $ticket): void
    {
        if ($ticket->status !== TicketStatus::Draft) {
            throw TicketRefused::because(__('This ticket has been sent already, so it cannot be changed here.'));
        }
    }

    /** @throws TicketRefused */
    private function refuseUnlessWorkable(Ticket $ticket): void
    {
        if ($ticket->status === TicketStatus::Draft || $ticket->status === TicketStatus::Closed) {
            throw TicketRefused::because(__('A ticket that is :status cannot be changed. Reopen it first.', ['status' => mb_strtolower(__($ticket->status->label()))]));
        }
    }

    private function lock(int $ticketId): Ticket
    {
        return Ticket::query()->whereKey($ticketId)->lockForUpdate()->firstOrFail();
    }

    /** What a move is called in a refusal ("A ticket that is closed cannot be resolved."). */
    private static function action(TicketStatus $to): string
    {
        return match ($to) {
            TicketStatus::Draft => __('made a draft again'),
            TicketStatus::Submitted => __('sent'),
            TicketStatus::UnderReview => __('put under review'),
            TicketStatus::InProgress => __('started'),
            TicketStatus::Resolved => __('resolved'),
            TicketStatus::Closed => __('closed'),
            TicketStatus::Reopened => __('reopened'),
        };
    }

    private static function id(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    private static function summary(Ticket $ticket): TicketSummary
    {
        return new TicketSummary(
            id: $ticket->id,
            organizationId: $ticket->organization_id,
            number: (string) $ticket->number,
            title: $ticket->title,
            status: $ticket->status->value,
            statusLabel: $ticket->status->label(),
            open: $ticket->status->isOpen(),
            category: $ticket->category->value,
            categoryLabel: $ticket->category->label(),
            priority: $ticket->priority->value,
            priorityLabel: $ticket->priority->label(),
            propertyId: $ticket->property_id,
            unitId: $ticket->unit_id,
            leaseId: $ticket->lease_id,
            tenantProfileId: $ticket->tenant_profile_id,
            assignedToUserId: $ticket->assigned_to_user_id,
            submittedAt: $ticket->submitted_at,
            resolvedAt: $ticket->resolved_at,
            closedAt: $ticket->closed_at,
        );
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, Ticket $ticket, ?User $by, string $description, array $properties = []): void
    {
        $entry = activity(self::LOG)
            ->event($event)
            ->performedOn($ticket)
            ->withProperties(['number' => $ticket->number, 'property_id' => $ticket->property_id, 'unit_id' => $ticket->unit_id, 'tenant_profile_id' => $ticket->tenant_profile_id, ...$properties]);

        if ($by !== null) {
            $entry->causedBy($by);
        }

        $entry->log($description);
    }
}
