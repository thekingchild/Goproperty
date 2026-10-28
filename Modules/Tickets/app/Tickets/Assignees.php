<?php

declare(strict_types=1);

namespace Modules\Tickets\Tickets;

use App\Enums\OrganizationPermission;
use App\Members\Members;
use App\Models\User;
use Modules\Properties\Contracts\BuildingAccess;

/**
 * Who a ticket may be assigned to (docs/plans/P04-subtasks.md default 5):
 * a member of the current organization whose roles grant `tickets.manage`
 * (org-owner, org-admin, agent) and who works on the ticket's building
 * (Modules\Properties\Contracts\BuildingAccess: owners and administrators
 * always, an agent when assigned to it or not narrowed at all). Property
 * owners and tenants never.
 */
final readonly class Assignees
{
    public function __construct(
        private Members $members,
        private BuildingAccess $access,
    ) {}

    /**
     * The members a ticket of this building may be assigned to, by name.
     *
     * @return array<int, string> user id => name
     */
    public function for(int $propertyId): array
    {
        $ids = $this->members->holdersOf(OrganizationPermission::ManageTickets);

        if ($ids === []) {
            return [];
        }

        return User::query()->whereKey($ids)->orderBy('name')->orderBy('id')->get()
            ->filter(fn (User $user): bool => $this->access->allows($user, $propertyId))
            ->mapWithKeys(static fn (User $user): array => [$user->id => (string) $user->name])
            ->all();
    }

    public function allows(int $userId, int $propertyId): bool
    {
        return array_key_exists($userId, $this->for($propertyId));
    }
}
