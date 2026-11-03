<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Groups;

use Modules\BuildingGroup\Models\BuildingGroup;

/**
 * A group's settings (`building_groups.settings`, DATABASE_SCHEMA.md § 6:
 * "who can post, image toggles"; P07-02, docs/plans/P07-subtasks.md
 * default 19), with their defaults when nothing is stored:
 *
 * - `members_post` (default true): tenants start posts; false keeps
 *   posting to the agency's staff while tenants still comment and react.
 * - `images` (default true): posts may carry images.
 *
 * Read and enforced since P07-02 (Policies\BuildingGroupPolicy); changed
 * by moderators on the group's page (P07-04, Moderation\GroupModeration).
 */
final readonly class GroupSettings
{
    public function __construct(
        public bool $membersPost = true,
        public bool $images = true,
    ) {}

    public static function of(BuildingGroup $group): self
    {
        $settings = $group->settings ?? [];

        return new self(
            membersPost: ($settings['members_post'] ?? true) !== false,
            images: ($settings['images'] ?? true) !== false,
        );
    }
}
