<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Groups;

/** What one BuildingGroups::sync() changed: the group (null when the building has none here and gets none), the tenants who joined and left. */
final readonly class MembershipChange
{
    /**
     * @param  list<int>  $joined  tenant profile ids
     * @param  list<int>  $left  tenant profile ids
     */
    public function __construct(
        public ?int $groupId,
        public bool $active,
        public array $joined = [],
        public array $left = [],
    ) {}

    public function changed(): bool
    {
        return $this->joined !== [] || $this->left !== [];
    }
}
