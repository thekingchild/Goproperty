<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The customer permission catalogue (ARCHITECTURE.md § 5): what a role can
 * do inside one organization, on the `web` guard. Seeded from here by
 * OrganizationRolesSeeder; roles get them through OrganizationRole. Later
 * tasks add the permissions their features need, here and nowhere else.
 *
 * `workspace.access` decides who may open /o/{slug} at all
 * (OrganizationPolicy::view): tenants and property owners use their
 * portals instead (ARCHITECTURE.md § 3, § 5).
 *
 * Record-level rules (a tenant's own leases, an agent's assigned buildings,
 * a ticket's envelope) stay in policies; a permission says only what a
 * role may do at all.
 */
enum OrganizationPermission: string
{
    use HasValues;

    public const string GUARD = 'web';

    case OpenWorkspace = 'workspace.access';
    case UpdateOrganization = 'organization.update';
    case DeleteOrganization = 'organization.delete';
    case ManageBilling = 'organization.billing';
    case ManagePaymentSettings = 'organization.payment_settings';
    case ManageCompliance = 'organization.compliance';
    case ViewActivity = 'organization.activity';
    case ViewMembers = 'members.view';
    case ManageMembers = 'members.manage';
    case ViewProperties = 'properties.view';
    case ManageProperties = 'properties.manage';
    case ViewLeases = 'leases.view';
    case ManageLeases = 'leases.manage';
    case ViewBills = 'bills.view';
    case ManageBills = 'bills.manage';
    case ViewPayments = 'payments.view';
    case ConfirmPayments = 'payments.confirm';
    case SubmitPaymentProof = 'payments.submit_proof';
    case ViewTickets = 'tickets.view';
    case CreateTickets = 'tickets.create';
    case RespondToTickets = 'tickets.respond';
    case ManageTickets = 'tickets.manage';
    case ParticipateInBuildingGroups = 'building_groups.participate';
    case ModerateBuildingGroups = 'building_groups.moderate';
    case ViewReports = 'reports.view';
    case ManageImports = 'imports.manage';

    public function description(): string
    {
        return match ($this) {
            self::OpenWorkspace => 'Open the organization\'s workspace (/o/{slug})',
            self::UpdateOrganization => 'Edit the organization profile and branding',
            self::DeleteOrganization => 'Close the organization',
            self::ManageBilling => 'Pay the GoProperty subscription, change plan, accept billing transfers',
            self::ManagePaymentSettings => 'Manage bank accounts and the organization\'s Paystack keys',
            self::ManageCompliance => 'Apply for a Transaction Tier and upload compliance documents',
            self::ViewActivity => 'See the organization\'s activity log',
            self::ViewMembers => 'See members and their roles',
            self::ManageMembers => 'Invite members, change their roles, remove them',
            self::ViewProperties => 'See buildings and units',
            self::ManageProperties => 'Add and edit buildings and units',
            self::ViewLeases => 'See leases',
            self::ManageLeases => 'Create, renew and end leases',
            self::ViewBills => 'See bills',
            self::ManageBills => 'Issue and void bills',
            self::ViewPayments => 'See payments',
            self::ConfirmPayments => 'Record, confirm and reject payments',
            self::SubmitPaymentProof => 'Upload proof of a payment',
            self::ViewTickets => 'See maintenance tickets',
            self::CreateTickets => 'Raise maintenance tickets',
            self::RespondToTickets => 'Reply to maintenance tickets',
            self::ManageTickets => 'Assign tickets and change their status',
            self::ParticipateInBuildingGroups => 'Post and comment in Building Groups',
            self::ModerateBuildingGroups => 'Moderate Building Groups',
            self::ViewReports => 'See financial reports',
            self::ManageImports => 'Import data from spreadsheets',
        };
    }
}
