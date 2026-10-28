<?php

declare(strict_types=1);

namespace Modules\Handovers\Handovers;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Handovers\Models\HandoverTenant;

/**
 * The tenant records earlier handovers between two organizations paired
 * up (BILLING_PAYMENTS.md § 7.4, product owner 2026-10-28, P04-04): "on a
 * return the tenant's original record, recorded on the handover, is used
 * first, then the phone". Each accepted handover's tenant lines pair the
 * record on the offering side (`tenant_profile_id`) with the one standing
 * for it on the receiving side (`receiving_tenant_profile_id`). Read for a
 * move from $from to $to, both ways:
 *
 * - handovers $to offered to $from (a landlord's management, about to end
 *   or followed by a one-way return): the receiving record in $from leads
 *   back to the original in $to;
 * - handovers $from offered to $to (handing the same tenants over again):
 *   the record in $from leads to the one made for it in $to before.
 *
 * Newer handovers win. Each organization's lines are read in its own
 * context (they belong to the organization that offered); only ids are
 * returned, and the Tenants module checks the record is still there.
 */
final readonly class TenantOrigins
{
    public function __construct(private OrganizationContext $context) {}

    /** @return array<int, int> tenant id in $from => their record in $to */
    public function between(Organization $from, Organization $to): array
    {
        $back = $this->context->run($to, fn (): array => $this->pairs($from->id, static fn (HandoverTenant $line): array => [(int) $line->receiving_tenant_profile_id, $line->tenant_profile_id]));
        $again = $this->context->run($from, fn (): array => $this->pairs($to->id, static fn (HandoverTenant $line): array => [$line->tenant_profile_id, (int) $line->receiving_tenant_profile_id]));

        // Oldest handover first, so the newest pairing wins.
        $lines = [...$back, ...$again];
        usort($lines, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $known = [];

        foreach ($lines as [, $fromTenant, $toTenant]) {
            $known[$fromTenant] = $toTenant;
        }

        return $known;
    }

    /**
     * Accepted handovers of the current organization to $otherId, their
     * tenant lines as [handover id, one side, other side].
     *
     * @param  callable(HandoverTenant): array{int, int}  $pair
     * @return list<array{int, int, int}>
     */
    private function pairs(int $otherId, callable $pair): array
    {
        return array_values(HandoverTenant::query()
            ->whereNotNull('receiving_tenant_profile_id')
            ->whereHas('handover', static fn ($query) => $query
                ->where('to_organization_id', $otherId)
                ->whereIn('status', [HandoverStatus::Active->value, HandoverStatus::Ending->value, HandoverStatus::Ended->value, HandoverStatus::Returned->value]))
            ->orderBy('management_handover_id')
            ->orderBy('id')
            ->get()
            ->map(static fn (HandoverTenant $line): array => [$line->management_handover_id, ...$pair($line)])
            ->all());
    }
}
