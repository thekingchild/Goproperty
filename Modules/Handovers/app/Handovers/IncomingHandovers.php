<?php

declare(strict_types=1);

namespace Modules\Handovers\Handovers;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Handovers\Models\ManagementHandover;

/**
 * The receiving side's view of management handovers: those addressed to
 * the current organization (its inbox, the accept screen, and the
 * buildings it manages for landlords).
 *
 * A handover row belongs to the offering organization, so the receiving
 * one cannot query it through the organization scope. As P01-04 does for
 * invitation links (P02 decision 2), this reads only `id` and
 * `organization_id` of the rows whose `to_organization_id` is the current
 * organization (taken from OrganizationContext, never from the request),
 * then loads each row inside its own organization's context. No scope is
 * removed; a row addressed to another organization is simply not found.
 * Permission checks stay with the caller (ManagementHandoverPolicy).
 */
final readonly class IncomingHandovers
{
    public function __construct(private OrganizationContext $context) {}

    /** The handover addressed to the current organization with this id, with its offering organization loaded. */
    public function find(int $handoverId): ?ManagementHandover
    {
        return $this->load($this->addressedHere()->where('id', $handoverId)->limit(1))->first();
    }

    /**
     * Handovers addressed to the current organization, newest first: the
     * open ones (waiting, managed, ending), then the closed ones.
     *
     * @return Collection<int, ManagementHandover>
     */
    public function recent(int $limit = 20): Collection
    {
        return self::ordered($this->load($this->addressedHere()->orderByDesc('id')->limit($limit)));
    }

    /**
     * Every handover addressed to the current organization, in the same
     * order as recent() (the inbox's export, P02-07). Offering is
     * rate-limited, so an organization receives a handful.
     *
     * @return Collection<int, ManagementHandover>
     */
    public function all(): Collection
    {
        return self::ordered($this->load($this->addressedHere()->orderByDesc('id')));
    }

    /**
     * @param  Collection<int, ManagementHandover>  $handovers
     * @return Collection<int, ManagementHandover>
     */
    public static function ordered(Collection $handovers): Collection
    {
        return $handovers
            ->sortBy(static fn (ManagementHandover $handover): array => [$handover->shownStatus()->isOpen() ? 0 : 1, -$handover->id])
            ->values();
    }

    private function addressedHere(): Builder
    {
        return DB::table('management_handovers')->where('to_organization_id', $this->context->id());
    }

    /** @return Collection<int, ManagementHandover> */
    private function load(Builder $rows): Collection
    {
        $receiver = $this->context->id();
        $byOrganization = $rows->get(['id', 'organization_id'])
            ->groupBy(static fn (object $row): int => (int) $row->organization_id);

        $handovers = new Collection;

        foreach (Organization::query()->whereKey($byOrganization->keys()->all())->get() as $organization) {
            $ids = $byOrganization->get($organization->id, new Collection)->map(static fn (object $row): int => (int) $row->id)->all();

            $loaded = $this->context->run($organization, static fn () => ManagementHandover::query()
                ->with(['initiatedBy', 'respondedBy', 'toOrganization', 'landlordOrganization', 'buildings', 'tenants'])
                ->whereKey($ids)
                ->where('to_organization_id', $receiver)
                ->get());

            foreach ($loaded as $handover) {
                $handovers->push($handover->setRelation('organization', $organization));
            }
        }

        return $handovers;
    }
}
