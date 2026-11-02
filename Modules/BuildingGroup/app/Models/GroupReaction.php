<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\BuildingGroup\Enums\ReactableType;
use Modules\BuildingGroup\Enums\Reaction;

/**
 * One person's reaction to a post or comment (DATABASE_SCHEMA.md § 6
 * `group_reactions`; P07-02): one per person and item, from the curated
 * set (Enums\Reaction). Written only by Posts\GroupPosts; a reaction is a
 * toggle, so taking it back deletes the row.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property ReactableType $reactable_type
 * @property int $reactable_id
 * @property Reaction $emoji
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class GroupReaction extends Model
{
    use BelongsToOrganization;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reactable_type' => ReactableType::class,
            'emoji' => Reaction::class,
        ];
    }
}
