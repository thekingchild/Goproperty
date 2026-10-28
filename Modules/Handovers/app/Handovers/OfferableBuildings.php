<?php

declare(strict_types=1);

namespace Modules\Handovers\Handovers;

use Illuminate\Support\Facades\DB;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Properties\Contracts\PropertyDirectory;

/**
 * The building picker of a handover offer (BILLING_PAYMENTS.md § 7.1):
 * the current organization's active buildings (Properties'
 * PropertyDirectory) that are in no open handover. A building is in at
 * most one open handover (pending, active or ending); the service checks
 * again under the organization's lock when the offer is made.
 */
final readonly class OfferableBuildings
{
    public function __construct(private PropertyDirectory $properties) {}

    /** @return array<int, string> id => name, by name */
    public function options(): array
    {
        $options = $this->properties->activeOptions();
        $busy = array_flip($this->inOpenHandover(array_keys($options)));

        return array_diff_key($options, $busy);
    }

    /**
     * Which of these buildings are in an open handover, whichever
     * organization made it. Reads ids only, so no organization's handover
     * is revealed.
     *
     * @param  list<int>  $propertyIds
     * @return list<int>
     */
    public function inOpenHandover(array $propertyIds): array
    {
        if ($propertyIds === []) {
            return [];
        }

        return array_values(DB::table('management_handover_buildings')
            ->join('management_handovers', 'management_handovers.id', '=', 'management_handover_buildings.management_handover_id')
            ->whereIn('management_handover_buildings.property_id', $propertyIds)
            ->where(static fn ($query) => $query
                ->whereIn('management_handovers.status', [HandoverStatus::Active->value, HandoverStatus::Ending->value])
                ->orWhere(static fn ($pending) => $pending->where('management_handovers.status', HandoverStatus::Pending->value)->where('management_handovers.expires_at', '>', now())))
            ->distinct()
            ->orderBy('management_handover_buildings.property_id')
            ->pluck('management_handover_buildings.property_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }
}
