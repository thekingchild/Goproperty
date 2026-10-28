<?php

declare(strict_types=1);

namespace Modules\Handovers\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A building of a management handover (`management_handover_buildings`,
 * DATABASE_SCHEMA.md § 1): the building's id (it keeps it when it moves),
 * its name and address as offered (each side can read the building itself
 * only while it is theirs), its units counted by the GoProperty bill, and
 * the owner profile it had in the landlord's organization (restored when
 * it returns). Organization-scoped like its handover (the offering
 * organization); written only by Handovers\HandoverService.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $management_handover_id
 * @property int $property_id
 * @property string $property_name
 * @property string|null $property_address
 * @property int $unit_count
 * @property int|null $previous_owner_profile_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ManagementHandover $handover
 */
final class HandoverBuilding extends Model
{
    use BelongsToOrganization;

    protected $table = 'management_handover_buildings';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['unit_count' => 'integer'];
    }

    /** @return BelongsTo<ManagementHandover, $this> */
    public function handover(): BelongsTo
    {
        return $this->belongsTo(ManagementHandover::class, 'management_handover_id');
    }
}
