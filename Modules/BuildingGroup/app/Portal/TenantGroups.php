<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Portal;

use App\Branding\BrandColours;
use App\Branding\Branding;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Support\Facades\Gate;
use Modules\BuildingGroup\Enums\PostStatus;
use Modules\BuildingGroup\Lists\GroupList;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupMember;
use Modules\Tenants\Contracts\PortalTenant;
use Modules\Tenants\Contracts\TenantPortalAccess;

/**
 * The tenant portal's Building Groups (P07-02), across every organization
 * the user rents from, through the Tenants module's portal path
 * (Contracts\TenantPortalAccess). Inside each organization's context, a
 * group is the user's while one of their tenant records there is a
 * current member (P07-01: an active lease in the building) and
 * BuildingGroupPolicy::viewAsTenant lets them in (the group open, the
 * agency's plan including Building Group). Any other group id is simply
 * not found (404).
 */
final readonly class TenantGroups
{
    public function __construct(
        private TenantPortalAccess $portal,
        private OrganizationContext $context,
        private GroupList $list,
    ) {}

    /** @return list<PortalGroup> by agency, then group id */
    public function groups(User $user): array
    {
        $groups = [];

        foreach ($this->portal->tenancies($user) as $tenancy) {
            $groups = [...$groups, ...$this->within($tenancy, function () use ($user, $tenancy): array {
                $branding = Branding::fromOrganization($tenancy->organization);
                $found = $this->memberGroups($user, $tenancy);
                $this->list->remember($found);

                return array_map(fn (BuildingGroup $group): PortalGroup => new PortalGroup(
                    id: $group->id,
                    name: $this->list->nameOf($group),
                    organizationId: $tenancy->organization->id,
                    agency: $branding->displayName(),
                    colour: BrandColours::textShade($branding->primaryColor),
                    initials: $branding->initials(),
                    readOnly: $tenancy->readOnly(),
                    posts: (int) $group->getAttribute('posts_count'),
                    latestActivity: GroupList::latestActivity($group),
                ), $found);
            })];
        }

        return $groups;
    }

    /**
     * The user's group $groupId with the tenancy it is in, or null (the
     * page answers 404).
     *
     * @return array{PortalTenant, BuildingGroup}|null
     */
    public function find(User $user, int $groupId): ?array
    {
        foreach ($this->portal->tenancies($user) as $tenancy) {
            $group = $this->within($tenancy, static function () use ($user, $groupId): ?BuildingGroup {
                $group = BuildingGroup::query()->find($groupId);

                return $group instanceof BuildingGroup && Gate::forUser($user)->allows('viewAsTenant', $group) ? $group : null;
            });

            if ($group !== null) {
                return [$tenancy, $group];
            }
        }

        return null;
    }

    /** The group's name: its own, else its building's. In the group's organization. */
    public function nameOf(BuildingGroup $group): string
    {
        return $this->list->nameOf($group);
    }

    /**
     * $callback inside the tenancy's organization.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function within(PortalTenant $tenancy, Closure $callback): mixed
    {
        return $this->context->run($tenancy->organization, $callback);
    }

    /** @return list<BuildingGroup> */
    private function memberGroups(User $user, PortalTenant $tenancy): array
    {
        $ids = GroupMember::query()->current()->whereIn('tenant_profile_id', $tenancy->tenantProfileIds)->pluck('building_group_id')->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return array_values(BuildingGroup::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->withCount(['posts as posts_count' => static fn ($query) => $query->where('status', PostStatus::Published)])
            ->withMax(['posts as latest_activity_at' => static fn ($query) => $query->where('status', PostStatus::Published)], 'last_activity_at')
            ->orderBy('id')
            ->get()
            ->filter(static fn (BuildingGroup $group): bool => Gate::forUser($user)->allows('viewAsTenant', $group))
            ->all());
    }
}
