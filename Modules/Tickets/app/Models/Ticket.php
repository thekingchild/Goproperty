<?php

declare(strict_types=1);

namespace Modules\Tickets\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Modules\Tickets\Enums\TicketCategory;
use Modules\Tickets\Enums\TicketPriority;
use Modules\Tickets\Enums\TicketSource;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Tickets\TicketDates;

/**
 * A maintenance ticket (DATABASE_SCHEMA.md § 5 `tickets`, ARCHITECTURE.md
 * § 6.6). Organization-scoped. Written only by Modules\Tickets\Tickets\
 * TicketService (AGENT.md § 3 rule 5): nothing here is mass assignable,
 * and the status changes only through the service's moves (TicketStatus).
 * Never deleted for good: only a tenant's unsent draft is discarded, as a
 * soft delete.
 *
 * A ticket that moved with its building on a handover (P04-05) is a new
 * ticket in the receiving organization (`moved_from_*`); the one left
 * behind is closed there for good (`moved_out_at`).
 *
 * The building's owner (`/owner`) sees the ticket only while a member has
 * shared it with them (P04-06, `shared_with_owner_at`; every ticket starts
 * hidden, docs/plans/owner-visibility-2026-10-29.md).
 *
 * The unit, its building, the lease and the tenant belong to other
 * modules: the ticket keeps their ids and reads them through their
 * contracts, never their models (ARCHITECTURE.md § 8 rule 3).
 *
 * @property int $id
 * @property int $organization_id
 * @property string|null $number
 * @property int $property_id
 * @property int $unit_id
 * @property int|null $lease_id
 * @property int|null $tenant_profile_id
 * @property TicketSource $source
 * @property int|null $raised_by_user_id
 * @property TicketCategory $category
 * @property TicketPriority $priority
 * @property TicketStatus $status
 * @property string $title
 * @property string|null $description
 * @property int|null $assigned_to_user_id
 * @property CarbonImmutable|null $assigned_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $reopened_at
 * @property int $reopen_count
 * @property int|null $moved_from_ticket_id
 * @property string|null $moved_from_organization
 * @property string|null $moved_from_number
 * @property string|null $moved_to_organization
 * @property CarbonImmutable|null $moved_out_at
 * @property CarbonImmutable|null $shared_with_owner_at
 * @property int|null $shared_with_owner_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class Ticket extends Model
{
    use BelongsToOrganization, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft', 'priority' => 'medium', 'reopen_count' => 0];

    protected static function booted(): void
    {
        self::forceDeleting(static fn (): never => throw new LogicException('Tickets are never deleted for good (AGENT.md § 3 rule 7).'));
    }

    /** The ticket's number ("TKT-000012"), or "Draft" before it is sent. */
    public function reference(): string
    {
        return $this->number ?? __('Draft');
    }

    /** "Moved from Eze Properties, was TKT-000012" on a ticket that came with its building (P04-05), else null. */
    public function movedFromLine(): ?string
    {
        if ($this->moved_from_organization === null) {
            return null;
        }

        return $this->moved_from_number !== null
            ? (string) __('Moved from :organization, was :number', ['organization' => $this->moved_from_organization, 'number' => $this->moved_from_number])
            : (string) __('Moved from :organization', ['organization' => $this->moved_from_organization]);
    }

    /** "Building moved to Adeyemi Realty Ltd on 4 Jan 2027" on a ticket left behind, else null. */
    public function movedToLine(): ?string
    {
        if ($this->moved_out_at === null) {
            return null;
        }

        return (string) __('Building moved to :organization on :date', ['organization' => (string) $this->moved_to_organization, 'date' => TicketDates::day($this->moved_out_at)]);
    }

    /** Whether this ticket was left behind when its building moved (closed for good here; it goes on there). */
    public function movedOut(): bool
    {
        return $this->moved_out_at !== null;
    }

    /** Whether a member shared it with the building's owner (P04-06): only then does the owner see it. */
    public function sharedWithOwner(): bool
    {
        return $this->shared_with_owner_at !== null;
    }

    /**
     * Its status history, oldest first.
     *
     * @return HasMany<TicketStatusChange, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(TicketStatusChange::class)->orderBy('changed_at')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => TicketSource::class,
            'category' => TicketCategory::class,
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'reopen_count' => 'integer',
            'assigned_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
            'moved_from_ticket_id' => 'integer',
            'moved_out_at' => 'immutable_datetime',
            'shared_with_owner_at' => 'immutable_datetime',
            'shared_with_owner_by_user_id' => 'integer',
        ];
    }
}
