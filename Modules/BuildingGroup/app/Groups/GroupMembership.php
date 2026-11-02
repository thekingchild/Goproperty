<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Groups;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupMember;
use Modules\Tenants\Contracts\TenantPortalAccess;

/**
 * A signed-in user's membership of a group as a tenant (P07-02;
 * docs/plans/P07-subtasks.md default 4): they are a member when one of
 * their own tenant records here (the Tenants module's TenantPortalAccess:
 * linked to the user, not archived; while staff impersonate, only the
 * session's organization) is a current member of the group — that is, it
 * holds an active lease in the building (P07-01). In the group's
 * organization's context. Answers are kept for the request, by user and
 * group: a page asks several abilities of the same group.
 */
final class GroupMembership
{
    /** @var array<string, GroupMember|false> */
    private array $rows = [];

    public function __construct(
        private readonly TenantPortalAccess $portal,
        private readonly OrganizationContext $context,
    ) {}

    /** The user's current member row in the group, or null. */
    public function rowFor(User $user, BuildingGroup $group): ?GroupMember
    {
        if (! $this->context->has() || $group->organization_id !== $this->context->id()) {
            return null;
        }

        $key = $group->organization_id.':'.$group->id.':'.$user->id;

        if (! array_key_exists($key, $this->rows)) {
            $profiles = $this->portal->profileIdsHere($user);
            $row = $profiles === [] ? null : GroupMember::query()
                ->where('building_group_id', $group->id)
                ->whereIn('tenant_profile_id', $profiles)
                ->current()
                ->orderBy('id')
                ->first();
            $this->rows[$key] = $row ?? false;
        }

        $row = $this->rows[$key];

        return $row instanceof GroupMember ? $row : null;
    }

    /** The tenant record the user is a member through, or null. */
    public function tenantProfileFor(User $user, BuildingGroup $group): ?int
    {
        return $this->rowFor($user, $group)?->tenant_profile_id;
    }

    /** Whether the member may not write now (P07-04 sets `muted_until`). */
    public function muted(GroupMember $member): bool
    {
        return $member->muted_until !== null && $member->muted_until->isAfter(CarbonImmutable::now());
    }

    /** Forget what was looked up (a membership changed during the request). */
    public function forget(): void
    {
        $this->rows = [];
    }
}
