<?php

declare(strict_types=1);

namespace Modules\Billing\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\Response;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Properties\Contracts\BuildingAccess;

/**
 * Meter readings (P03-06a; ARCHITECTURE.md § 5 "Utilities"):
 * `utilities.view` (org-owner, org-admin, agent; kept while suspended) sees
 * them, their meter photos, and exports the list;
 * `utilities.record_readings` (the same three) opens the record page
 * (`create`; the reading itself is checked by
 * UnitUtilitySettingPolicy::recordReading), and corrects (`update`) or
 * removes (`delete`, a soft delete keeping the photo) the latest reading of
 * a meter while it is not billed. Changes go through
 * Utilities\MeterReadings, which checks again under the meter's lock.
 *
 * Agent narrowing (P02-03, decision 9): a reading follows its unit's
 * building (Contracts\BuildingAccess on the setting's `property_id`); any
 * other reading answers 404. Another organization's reading is refused.
 * Central guards: a suspended organization only reads (the photo too);
 * while staff impersonate, removing is a `delete` ability and refused in
 * both modes; recording and correcting are allowed in write mode, audited
 * with both identities (a meter photo holds no personal data).
 */
final class UtilityReadingPolicy
{
    use ChecksOrganizationPermission;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly BuildingAccess $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewUtilities);
    }

    /** Export the meter readings list (P02-07; already narrowed; no contact details). */
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, UtilityReading $reading): Response|bool
    {
        return $this->reachable($user, $reading) ?? $this->viewAny($user);
    }

    /** The meter photo (evidence), through a short-lived signed link. */
    public function viewPhoto(User $user, UtilityReading $reading): Response|bool
    {
        return $this->view($user, $reading);
    }

    /** Open the record page (each reading is checked against its meter: UnitUtilitySettingPolicy::recordReading). */
    public function create(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::RecordMeterReadings);
    }

    /** Correct the latest kept reading of its meter while it is not billed. */
    public function update(User $user, UtilityReading $reading): Response|bool
    {
        return $this->reachable($user, $reading)
            ?? (! $reading->trashed() && ! $reading->isBilled() && $this->isLatest($reading) && $this->create($user));
    }

    /** Remove (soft delete, the photo kept) the latest kept reading of its meter while it is not billed. */
    public function delete(User $user, UtilityReading $reading): Response|bool
    {
        return $this->update($user, $reading);
    }

    private function isLatest(UtilityReading $reading): bool
    {
        $latest = UtilityReading::query()
            ->where('unit_utility_setting_id', $reading->unit_utility_setting_id)
            ->orderByDesc('reading_date')
            ->orderByDesc('id')
            ->value('id');

        return is_numeric($latest) && (int) $latest === $reading->id;
    }

    private function reachable(User $user, UtilityReading $reading): Response|false|null
    {
        if (! $this->context->has() || $reading->organization_id !== $this->context->id()) {
            return false;
        }

        $propertyId = UnitUtilitySetting::query()->whereKey($reading->unit_utility_setting_id)->value('property_id');

        if (! is_numeric($propertyId)) {
            return false;
        }

        return $this->access->allows($user, (int) $propertyId) ? null : Response::denyAsNotFound();
    }
}
