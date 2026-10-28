<?php

declare(strict_types=1);

namespace Modules\Billing\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\Response;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Properties\Contracts\BuildingAccess;

/**
 * Which utilities units are charged (P03-06a; ARCHITECTURE.md § 5
 * "Utilities"): `utilities.view` (org-owner, org-admin, agent; kept while
 * suspended) sees the settings and exports the list; `utilities.manage`
 * (org-owner, org-admin) adds a utility to units, switches it on or off and
 * overrides its amount, cycle and starting reading (through
 * Utilities\UnitUtilitySettings, which checks again). `recordReading`
 * (`utilities.record_readings`: org-owner, org-admin, agent) records a
 * meter reading for a switched-on metered utility (Utilities\MeterReadings).
 *
 * Agent narrowing (P02-03, decision 9): an agent assigned to buildings sees
 * and reads meters only in those buildings (Contracts\BuildingAccess on
 * `unit_utility_settings.property_id`); any other setting answers 404,
 * checked before the ability's own rule. Another organization's setting is
 * refused. Central guards on top: a suspended organization only reads; no
 * ability here is blocked while staff impersonate (a write session is
 * audited with both identities).
 */
final class UnitUtilitySettingPolicy
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

    /** Export the units' utilities list (P02-07; already narrowed; no contact details). */
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, UnitUtilitySetting $setting): Response|bool
    {
        return $this->reachable($user, $setting) ?? $this->viewAny($user);
    }

    /** Add a utility to units (the writer checks the building against the member's narrowing too). */
    public function create(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManageUtilities);
    }

    public function update(User $user, UnitUtilitySetting $setting): Response|bool
    {
        return $this->reachable($user, $setting) ?? $this->create($user);
    }

    /** Record a meter reading: a switched-on metered utility of an active type. */
    public function recordReading(User $user, UnitUtilitySetting $setting): Response|bool
    {
        return $this->reachable($user, $setting)
            ?? ($setting->takesReadings() && $this->holds($user, OrganizationPermission::RecordMeterReadings));
    }

    /**
     * Null when the setting is this organization's and in the member's
     * buildings; false for another organization's; 404 outside a narrowed
     * agent's buildings.
     */
    private function reachable(User $user, UnitUtilitySetting $setting): Response|false|null
    {
        if (! $this->context->has() || $setting->organization_id !== $this->context->id()) {
            return false;
        }

        return $this->access->allows($user, $setting->property_id) ? null : Response::denyAsNotFound();
    }
}
