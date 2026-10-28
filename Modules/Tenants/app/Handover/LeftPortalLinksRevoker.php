<?php

declare(strict_types=1);

namespace Modules\Tenants\Handover;

use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Tenancy\Moves\BuildingMove;
use App\Tenancy\Moves\MovesBuildingRecords;
use Modules\Leasing\Contracts\TenantLeases;
use Modules\Tenants\Models\TenantProfile;
use Modules\Tenants\Profiles\TenantProfiles;

/**
 * After a management handover moved tenants (BILLING_PAYMENTS.md § 7.4,
 * P04-04; the P03-01b note): the portal invitations and one-time sign-in
 * links still waiting on the records they left (Core invitations of kind
 * `tenant` and `sign_in_link`, `tenant_profile_id`) are revoked
 * (`cancelled`), so no link opens a portal on the side the tenant left.
 * The organization they moved to invites them again; a tenant who already
 * has a portal account keeps it (their new record carries it). A tenant
 * who still holds a lease on the side they left (another building there)
 * keeps their links. Runs after Leasing's part (order 35), so the leases
 * that moved are no longer counted there. Each revocation is written to the
 * tenant's audit history (log `tenants`) with the handover, caused by
 * whoever's step moved them (the accepting owner; none for the hourly end).
 */
final readonly class LeftPortalLinksRevoker implements MovesBuildingRecords
{
    public function order(): int
    {
        return 35;
    }

    public function move(BuildingMove $move): void
    {
        $leases = app()->bound(TenantLeases::class) ? app(TenantLeases::class) : null;

        foreach (array_keys($move->tenantMap()) as $tenantId) {
            if ($leases?->holdsUnit($tenantId) === true) {
                continue;
            }

            $links = Invitation::query()
                ->whereIn('kind', [InvitationKind::Tenant, InvitationKind::SignInLink])
                ->where('tenant_profile_id', $tenantId)
                ->where('status', InvitationStatus::Pending)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($links->isEmpty()) {
                continue;
            }

            $tenant = TenantProfile::query()->withTrashed()->find($tenantId);

            foreach ($links as $link) {
                $link->forceFill(['status' => InvitationStatus::Cancelled, 'cancelled_at' => now()])->save();
                $sign = $link->kind === InvitationKind::SignInLink;

                $entry = activity(TenantProfiles::LOG)
                    ->event($sign ? 'tenant.sign_in_link_revoked' : 'tenant.portal_invitation_revoked')
                    ->withProperties([
                        'tenant' => $tenant?->fullName(),
                        'invitation_id' => $link->id,
                        'channel' => $link->channel->value,
                        'reason' => 'moved',
                        'handover_id' => $move->handoverId,
                        'to_organization_id' => $move->to->id,
                    ]);

                if ($tenant !== null) {
                    $entry->performedOn($tenant);
                }

                $entry->log($sign
                    ? "Revoked the one-time sign-in link: the tenant moved to {$move->to->name} with their building"
                    : "Revoked the portal invitation: the tenant moved to {$move->to->name} with their building");
            }

            $move->count('portal_links_revoked', $links->count());
        }
    }
}
