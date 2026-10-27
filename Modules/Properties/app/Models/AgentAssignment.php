<?php

declare(strict_types=1);

namespace Modules\Properties\Models;

use App\Models\User;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Properties\Database\Factories\AgentAssignmentFactory;

/**
 * An agent assigned to a building (DATABASE_SCHEMA.md § 3 `agent_building`,
 * ARCHITECTURE.md § 5): organization-scoped. An agent with at least one
 * assignment sees and works on those buildings only
 * (Assignments\AgentNarrowing). Written only through
 * Assignments\AgentAssignments, which checks PropertyPolicy::assignAgents
 * and audits every change.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property int $property_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $user
 * @property-read Property|null $property
 */
final class AgentAssignment extends Model
{
    /** @use HasFactory<AgentAssignmentFactory> */
    use BelongsToOrganization, HasFactory;

    protected $table = 'agent_building';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    protected static function newFactory(): AgentAssignmentFactory
    {
        return AgentAssignmentFactory::new();
    }
}
