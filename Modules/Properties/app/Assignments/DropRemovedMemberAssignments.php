<?php

declare(strict_types=1);

namespace Modules\Properties\Assignments;

use App\Members\MemberRemoved;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;

/**
 * A member who leaves an organization loses their building assignments
 * there (P02-03), in the removal's transaction, audited per building. A
 * member invited back starts unassigned, seeing every building until
 * assigned again.
 */
final readonly class DropRemovedMemberAssignments
{
    public function __construct(
        private OrganizationContext $context,
        private AgentAssignments $assignments,
    ) {}

    public function handle(MemberRemoved $event): void
    {
        $organization = Organization::query()->findOrFail($event->organizationId);
        $by = $event->removedByUserId !== null ? User::query()->find($event->removedByUserId) : null;

        $this->context->run($organization, fn (): int => $this->assignments->dropMember($event->userId, $by));
    }
}
