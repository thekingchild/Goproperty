<?php

declare(strict_types=1);

namespace App\Tenancy\Moves;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\BelongsToOrganization;
use App\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use LogicException;

/**
 * One building move in progress (ARCHITECTURE.md § 6.1, BILLING_PAYMENTS.md
 * § 7.4): which buildings go from which organization to which, and what
 * the movers did so far. Made only by OrganizationMoves::run() and handed
 * to each MovesBuildingRecords in turn, inside the handover's transaction.
 *
 * reassign() is the one sanctioned way to change a record's
 * `organization_id` (BelongsToOrganization refuses it on every save): it
 * works only while the move runs, only on BelongsToOrganization models,
 * only on rows that are in the organization the buildings leave (the
 * query runs in its context, through its scope), and it refuses the whole
 * move when any asked-for row is not there. Every call is logged here and
 * written to the audit log by the handover service.
 */
final class BuildingMove
{
    private bool $running = false;

    /** @var array<class-string<Model>, list<int>> */
    private array $moved = [];

    /** @var array<int, int> landlord-side tenant id => tenant id in the organization the buildings go to */
    private array $tenantMap = [];

    private ?int $ownerProfileId = null;

    /** @var array<int, int|null> building id => its owner profile before the move */
    private array $previousOwnerProfileIds = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<Closure(): void> */
    private array $afterCommit = [];

    /** @var list<Closure(): void> */
    private array $onFailure = [];

    /**
     * @param  list<int>  $propertyIds  the buildings that move
     * @param  array<int, int|null>  $restoreOwnerProfileIds  on a return: building id => its owner profile in $to before the handover
     * @param  array<int, int>  $knownTenants  tenant id in $from => the same tenant's record in $to, as an earlier handover recorded it (P04-04: used before the phone match)
     */
    public function __construct(
        public readonly Organization $from,
        public readonly Organization $to,
        public readonly MoveDirection $direction,
        public readonly array $propertyIds,
        public readonly int $handoverId,
        /** The landlord's organization (where buildings return). */
        public readonly Organization $landlordOrganization,
        /** The landlord's user: linked as the buildings' owner where they go (handover), unlinked where they leave (return); on a one-way return, the account linked to the buildings' owner record in the agency, whose owner record in their own organization takes the buildings. */
        public readonly ?User $landlord,
        /** On a return: the landlord's owner profile in $from, unlinked when they own nothing more there. */
        public readonly ?int $landlordOwnerProfileId,
        public readonly array $restoreOwnerProfileIds,
        private readonly OrganizationContext $context,
        public readonly array $knownTenants = [],
    ) {}

    /**
     * Moves these rows of $model from $from to $to, setting $attributes on
     * them too (a new slug, a cleared reference). Soft-deleted rows are
     * included when $withTrashed (archived units, removed meter readings).
     * Returns how many moved; throws when any of them is not in $from.
     *
     * @param  class-string<Model>  $model
     * @param  array<int|string>  $ids  record ids (normalised to a list of integers)
     * @param  array<string, mixed>  $attributes
     *
     * @throws BuildingMoveFailed
     */
    public function reassign(string $model, array $ids, array $attributes = [], bool $withTrashed = false): int
    {
        if (! $this->running) {
            throw new LogicException('Records change organization only while a building move runs (OrganizationMoves::run).');
        }

        if (! in_array(BelongsToOrganization::class, class_uses_recursive($model), true)) {
            throw new LogicException("{$model} is not an organization-owned model.");
        }

        if (array_key_exists('organization_id', $attributes)) {
            throw new LogicException('The organization is set by the move itself.');
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return 0;
        }

        $moved = $this->context->run($this->from, function () use ($model, $ids, $attributes, $withTrashed): int {
            $query = $model::query();

            if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                $query->withoutGlobalScope(SoftDeletingScope::class);
            }

            return $query->whereKey($ids)->update([...$attributes, 'organization_id' => $this->to->id]);
        });

        if ($moved !== count($ids)) {
            throw new BuildingMoveFailed(class_basename($model).': '.(count($ids) - $moved).' of '.count($ids).' records are no longer in '.$this->from->name.'.');
        }

        $this->moved[$model] = array_values(array_unique([...($this->moved[$model] ?? []), ...$ids]));

        return $moved;
    }

    /**
     * What moved so far, by model.
     *
     * @return array<class-string<Model>, list<int>>
     */
    public function moved(): array
    {
        return $this->moved;
    }

    /** The tenant that stands for $fromTenantId where the buildings go (Tenants' mover sets it). */
    public function mapTenant(int $fromTenantId, int $toTenantId): void
    {
        $this->tenantMap[$fromTenantId] = $toTenantId;
    }

    /**
     * The tenant's own record where the buildings go, as an earlier handover
     * between the two organizations recorded it (BILLING_PAYMENTS.md § 7.4:
     * "the tenant's original record, recorded on the handover, is used
     * first, then the phone"), if any. The Tenants mover checks it is still
     * there.
     */
    public function knownTenantIn(int $fromTenantId): ?int
    {
        return $this->knownTenants[$fromTenantId] ?? null;
    }

    public function tenantIn(int $fromTenantId): ?int
    {
        return $this->tenantMap[$fromTenantId] ?? null;
    }

    /** @return array<int, int> */
    public function tenantMap(): array
    {
        return $this->tenantMap;
    }

    /** The landlord's owner profile where the buildings went (handover; Properties' mover sets it). */
    public function setOwnerProfileId(?int $ownerProfileId): void
    {
        $this->ownerProfileId = $ownerProfileId;
    }

    public function ownerProfileId(): ?int
    {
        return $this->ownerProfileId;
    }

    public function setPreviousOwnerProfileId(int $propertyId, ?int $ownerProfileId): void
    {
        $this->previousOwnerProfileIds[$propertyId] = $ownerProfileId;
    }

    /** @return array<int, int|null> */
    public function previousOwnerProfileIds(): array
    {
        return $this->previousOwnerProfileIds;
    }

    /** Adds to a count for the audit entry ("agent_assignments_dropped", "external_refs_cleared", …). */
    public function count(string $what, int $by = 1): void
    {
        $this->counts[$what] = ($this->counts[$what] ?? 0) + $by;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }

    /** Work for after the transaction commits (removing the old copies of moved files). */
    public function afterCommit(Closure $callback): void
    {
        $this->afterCommit[] = $callback;
    }

    /** Undo for work outside the database when the move fails (removing new copies of files). */
    public function onFailure(Closure $callback): void
    {
        $this->onFailure[] = $callback;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /** @internal for OrganizationMoves */
    public function start(): void
    {
        $this->running = true;
    }

    /** @internal for OrganizationMoves */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * @internal for OrganizationMoves
     *
     * @return list<Closure(): void>
     */
    public function afterCommitCallbacks(): array
    {
        return $this->afterCommit;
    }

    /** Runs the undo callbacks once (newest first); for OrganizationMoves and the caller's own failure handling. */
    public function discard(): void
    {
        $callbacks = array_reverse($this->onFailure);
        $this->onFailure = [];
        $this->afterCommit = [];

        foreach ($callbacks as $callback) {
            $callback();
        }
    }
}
