<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A building's discussion group (DATABASE_SCHEMA.md § 6 `building_groups`;
 * ARCHITECTURE.md § 6.8; P07-01): one per building and organization.
 * Active while the building is one of the organization's active buildings;
 * closed (`is_active` false, `closed_at` set) when it is archived, removed
 * by an import rollback, or handed to another organization. `name` empty
 * means the building's name. Written only by Groups\BuildingGroups; never
 * deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $property_id
 * @property string|null $name
 * @property string|null $description
 * @property array<string, mixed>|null $settings
 * @property bool $is_active
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class BuildingGroup extends Model
{
    use BelongsToOrganization;

    /** Only what BuildingGroups opens a group with. */
    protected $fillable = ['property_id', 'is_active'];

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('A Building Group is never deleted; it is closed.'));
    }

    /** @return HasMany<GroupMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
