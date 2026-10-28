<?php

declare(strict_types=1);

namespace Modules\Handovers\Handovers;

use App\Tenancy\Moves\MovedTenant;
use App\Tenancy\Moves\MovedTenants;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Handovers\Models\HandoverTenant;

/**
 * The Handovers module's answer to Core's MovedTenants (BILLING_PAYMENTS.md
 * § 7.4, P04-04): the tenant lines of the current organization's own
 * handovers whose tenants are elsewhere now (a management still on, or a
 * one-way return; HandoverStatus::tenantsMovedOut()), with the receiving
 * organization's name and the acceptance date. Nothing of the other
 * organization is read but its name.
 */
final readonly class MovedTenantRecords implements MovedTenants
{
    public function movedOut(array $tenantProfileIds): array
    {
        if ($tenantProfileIds === []) {
            return [];
        }

        $moved = [];

        foreach ($this->lines($tenantProfileIds) as $line) {
            $handover = $line->handover;
            $at = $handover->accepted_at ?? $handover->responded_at;

            if ($at === null) {
                continue;
            }

            $moved[$line->tenant_profile_id] = new MovedTenant(
                tenantProfileId: $line->tenant_profile_id,
                organizationName: $handover->toOrganization->name,
                movedAt: CarbonImmutable::instance($at),
                handoverId: $handover->id,
            );
        }

        return $moved;
    }

    public function movedOutIds(): array
    {
        return array_values(array_unique(array_map(static fn (HandoverTenant $line): int => $line->tenant_profile_id, $this->lines(null))));
    }

    public function organizationIds(): array
    {
        return array_values(DB::table('management_handovers')
            ->whereIn('status', HandoverStatus::movedOutValues())
            ->whereExists(static fn ($lines) => $lines->selectRaw('1')->from('management_handover_tenants')
                ->whereColumn('management_handover_tenants.management_handover_id', 'management_handovers.id')
                ->whereNotNull('management_handover_tenants.receiving_tenant_profile_id'))
            ->distinct()
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * Oldest first, so a newer move of the same tenant overwrites.
     *
     * @param  list<int>|null  $tenantProfileIds
     * @return list<HandoverTenant>
     */
    private function lines(?array $tenantProfileIds): array
    {
        return array_values(HandoverTenant::query()
            ->with('handover.toOrganization')
            ->whereNotNull('receiving_tenant_profile_id')
            ->when($tenantProfileIds !== null, static fn ($query) => $query->whereIn('tenant_profile_id', $tenantProfileIds))
            ->whereHas('handover', static fn ($query) => $query->whereIn('status', HandoverStatus::movedOutValues()))
            ->orderBy('management_handover_id')
            ->get()
            ->all());
    }
}
