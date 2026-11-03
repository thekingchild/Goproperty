<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Live;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Portal\TenantGroups;

/**
 * Who may listen on a group's private channel `group.{id}` (P07-03;
 * ARCHITECTURE.md § 6.8): exactly who may open the group's page now. A
 * tenant through the portal's rule (Portal\TenantGroups: a current member
 * of an open group whose agency's plan has Building Group); a member of
 * staff through the workspace's (`view`, inside each organization whose
 * workspace they open: the plan switch and an agent's buildings
 * included). Anyone else, a guest or an id that is no group, is refused.
 * Each organization is entered through its context, so the group is never
 * read across organizations. Listening tells only that something changed;
 * the page then asks for its feed, checked again.
 */
final readonly class GroupChannel
{
    /** The channel's name pattern (LiveFeed::channel() names one). */
    public const string PATTERN = 'group.{group}';

    public function __construct(
        private TenantGroups $tenantGroups,
        private OrganizationContext $context,
    ) {}

    /** Registers the channel on the broadcaster in use (the module's provider, at boot). */
    public static function register(): void
    {
        Broadcast::channel(self::PATTERN, self::class);
    }

    public function join(User $user, string $group): bool
    {
        if (preg_match('/^[1-9]\d{0,18}$/', $group) !== 1) {
            return false;
        }

        $groupId = (int) $group;

        if ($this->tenantGroups->find($user, $groupId) !== null) {
            return true;
        }

        $organizations = Organization::query()->whereIn('id', $user->workspaceOrganizationIds())->get();

        foreach ($organizations as $organization) {
            $sees = $this->context->run($organization, static function () use ($user, $groupId): bool {
                $found = BuildingGroup::query()->find($groupId);

                return $found instanceof BuildingGroup && Gate::forUser($user)->allows('view', $found);
            });

            if ($sees) {
                return true;
            }
        }

        return false;
    }
}
