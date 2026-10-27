<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\OrganizationPermission as P;

/**
 * Customer roles, assigned per organization through spatie teams
 * (ARCHITECTURE.md § 5). Shared by every organization: the role rows have no
 * organization, the assignment does. Owners, administrators and agents work
 * in the organization's workspace (/o/{slug}); property owners and tenants
 * never do: they use the cross-agency portals (/owner, /portal).
 */
enum OrganizationRole: string
{
    use HasValues;

    case Owner = 'org-owner';
    case Admin = 'org-admin';
    case Agent = 'agent';
    case PropertyOwner = 'property-owner';
    case Tenant = 'tenant';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Administrator',
            self::Agent => 'Agent',
            self::PropertyOwner => 'Property owner',
            self::Tenant => 'Tenant',
        };
    }

    /** Whether the role opens the workspace at /o/{slug} (`workspace.access`). */
    public function opensWorkspace(): bool
    {
        return in_array(P::OpenWorkspace, $this->permissions(), true);
    }

    /** @return list<P> */
    public function permissions(): array
    {
        $operations = [
            P::ViewProperties, P::ManageProperties, P::ViewLeases, P::ManageLeases, P::ViewBills, P::ManageBills,
            P::ViewPayments, P::ConfirmPayments, P::ViewTickets, P::CreateTickets, P::RespondToTickets, P::ManageTickets,
            P::ParticipateInBuildingGroups, P::ModerateBuildingGroups,
        ];

        return match ($this) {
            // Full control, including billing responsibility and closing the organization.
            self::Owner => P::cases(),
            // Day-to-day operations, profile and branding; no billing
            // responsibility, bank accounts or Paystack (where tenants' money
            // goes: owner only), compliance or closing the organization.
            self::Admin => [P::OpenWorkspace, P::UpdateOrganization, P::ViewSettings, P::ViewMembers, P::ManageMembers, ...$operations, P::ViewReports, P::ManageImports],
            // Operational staff; building assignment narrows this in policies.
            self::Agent => [P::OpenWorkspace, ...$operations],
            // Owner portal only (/owner), own properties only (policies narrow it).
            self::PropertyOwner => [P::ViewProperties, P::ViewLeases, P::ViewBills, P::ViewPayments, P::ViewTickets, P::RespondToTickets, P::ViewReports],
            // Tenant portal only (/portal), own leases only.
            self::Tenant => [P::SubmitPaymentProof, P::CreateTickets, P::ParticipateInBuildingGroups],
        };
    }
}
