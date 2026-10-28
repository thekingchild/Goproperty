<?php

declare(strict_types=1);

namespace Modules\Properties\Owners;

use Modules\Properties\Buildings\PropertyDirectoryService;
use Modules\Properties\Contracts\OwnerProfileDirectory;
use Modules\Properties\Contracts\OwnerSummary;
use Modules\Properties\Contracts\PropertySummary;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;

/** The OwnerProfileDirectory contract (P08-05): reads only, in the current organization. */
final readonly class OwnerProfileDirectoryService implements OwnerProfileDirectory
{
    public function find(int $ownerProfileId): ?OwnerSummary
    {
        $owner = OwnerProfile::query()->withTrashed()->find($ownerProfileId);

        return $owner instanceof OwnerProfile ? new OwnerSummary(
            id: $owner->id,
            organizationId: $owner->organization_id,
            name: $owner->display_name,
            archived: $owner->deleted_at !== null,
            portalUserId: $owner->user_id,
        ) : null;
    }

    public function buildingsOf(int $ownerProfileId): array
    {
        return array_values(Property::query()->with('owner')->where('owner_profile_id', $ownerProfileId)
            ->orderBy('name')->orderBy('id')->get()
            ->map(static fn (Property $property): PropertySummary => PropertyDirectoryService::summary($property))
            ->all());
    }
}
