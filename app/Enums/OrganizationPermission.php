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
    case ViewSettings = 'settings.view';
    case DeleteOrganization = 'organization.delete';
    case ManageBilling = 'organization.billing';
    case ManagePaymentSettings = 'organization.payment_settings';
    case ViewPaymentSettings = 'payment_settings.view';
    case ManageCompliance = 'organization.compliance';
    case ViewCompliance = 'compliance.view';
    case ViewActivity = 'organization.activity';
    case ViewMembers = 'members.view';
    case ManageMembers = 'members.manage';
    case ViewOwners = 'owners.view';
    case ManageOwners = 'owners.manage';
    case ViewProperties = 'properties.view';
    case ManageProperties = 'properties.manage';
    case ManagePropertyPortfolio = 'properties.portfolio';
    case AssignAgents = 'properties.assign_agents';
    case ViewTenants = 'tenants.view';
    case ManageTenants = 'tenants.manage';
    case ManageTenantPortalAccess = 'tenants.portal_access';
    case ViewLeases = 'leases.view';
    case ManageLeases = 'leases.manage';
    case ViewBills = 'bills.view';
    case ManageBills = 'bills.manage';
    case ViewUtilities = 'utilities.view';
    case ManageUtilities = 'utilities.manage';
    case RecordMeterReadings = 'utilities.record_readings';
    case ViewPayments = 'payments.view';
    case ConfirmPayments = 'payments.confirm';
    case SubmitPaymentProof = 'payments.submit_proof';
    case ViewStatements = 'statements.view';
    case ImportStatements = 'statements.import';
    case MatchStatements = 'statements.match';
    case ApproveReceiptVoids = 'receipts.approve_void';
    case ViewTickets = 'tickets.view';
    case CreateTickets = 'tickets.create';
    case RespondToTickets = 'tickets.respond';
    case ManageTickets = 'tickets.manage';
    case ParticipateInBuildingGroups = 'building_groups.participate';
    case ModerateBuildingGroups = 'building_groups.moderate';
    case ViewReports = 'reports.view';
    case ViewImports = 'imports.view';
    case ManageImports = 'imports.manage';

    /**
     * Whether members keep this permission while their organization is
     * suspended or cancelled (OrganizationWriteGuard): reading, exporting,
     * paying the GoProperty subscription (a confirmed payment reactivates,
     * ONBOARDING_COMPLIANCE.md § 3) and a tenant's payment proof (the portal
     * keeps accepting proofs). Every new permission must be placed here;
     * the match has no default, so one left out fails the build.
     */
    public function allowedWhileSuspended(): bool
    {
        return match ($this) {
            self::OpenWorkspace,
            self::ViewSettings,
            self::ViewPaymentSettings,
            self::ViewCompliance,
            self::ViewActivity,
            self::ViewMembers,
            self::ViewOwners,
            self::ViewProperties,
            self::ViewTenants,
            self::ViewLeases,
            self::ViewBills,
            self::ViewUtilities,
            self::ViewPayments,
            self::ViewStatements,
            self::ViewTickets,
            self::ViewReports,
            self::ViewImports,
            self::ManageBilling,
            self::SubmitPaymentProof => true,
            self::UpdateOrganization,
            self::DeleteOrganization,
            self::ManagePaymentSettings,
            self::ManageCompliance,
            self::ManageMembers,
            self::ManageOwners,
            self::ManageProperties,
            self::ManagePropertyPortfolio,
            self::AssignAgents,
            self::ManageTenants,
            self::ManageTenantPortalAccess,
            self::ManageLeases,
            self::ManageBills,
            self::ManageUtilities,
            self::RecordMeterReadings,
            self::ConfirmPayments,
            self::ImportStatements,
            self::MatchStatements,
            self::ApproveReceiptVoids,
            self::CreateTickets,
            self::RespondToTickets,
            self::ManageTickets,
            self::ParticipateInBuildingGroups,
            self::ModerateBuildingGroups,
            self::ManageImports => false,
        };
    }

    /**
     * Whether GoProperty staff lose this permission while impersonating a
     * user, in read-only and write mode alike (PLATFORM_CONSOLE.md § 6,
     * "always blocked"; App\Tenancy\ImpersonationGuard): money actions
     * (confirming or rejecting payments, payment proofs, issuing and voiding
     * bills, approving receipt voids, the subscription and billing transfers), bank accounts and
     * Paystack keys (viewing them too: the Payment collection page needs
     * `payment_settings.view`, also blocked), members and roles (assigning
     * agents to buildings too: it decides what a member sees), tenants'
     * portal access (invitations, one-time sign-in links and confirming a
     * phone number: they decide who signs in to an account, P03-01b),
     * closing the organization, data
     * imports (commit and rollback, DATA_IMPORT.md § 5), bank statement
     * uploads (what they bring in becomes payments once matched, P03-07a;
     * seeing statements stays allowed) and statement matching (it records
     * and confirms payments, P03-07b), and Transaction Tier
     * applications and compliance documents (`organization.compliance`: they
     * change the organization's compliance standing, so staff never apply,
     * upload or submit for a customer; seeing them, `compliance.view`, stays
     * allowed). Read-only sessions
     * also lose every permission that writes (allowedWhileSuspended() false).
     * Every new permission must be placed here; the match has no default,
     * so one left out fails the build.
     */
    public function blockedWhileImpersonating(): bool
    {
        return match ($this) {
            self::DeleteOrganization,
            self::ManageBilling,
            self::ManagePaymentSettings,
            self::ViewPaymentSettings,
            self::ManageCompliance,
            self::ManageMembers,
            self::AssignAgents,
            self::ManageTenantPortalAccess,
            self::ManageBills,
            self::ConfirmPayments,
            self::ApproveReceiptVoids,
            self::SubmitPaymentProof,
            self::ImportStatements,
            self::MatchStatements,
            self::ManageImports => true,
            self::OpenWorkspace,
            self::UpdateOrganization,
            self::ViewSettings,
            self::ViewCompliance,
            self::ViewActivity,
            self::ViewMembers,
            self::ViewOwners,
            self::ManageOwners,
            self::ViewProperties,
            self::ManageProperties,
            self::ManagePropertyPortfolio,
            self::ViewTenants,
            self::ManageTenants,
            self::ViewLeases,
            self::ManageLeases,
            self::ViewBills,
            self::ViewUtilities,
            self::ManageUtilities,
            self::RecordMeterReadings,
            self::ViewPayments,
            self::ViewStatements,
            self::ViewTickets,
            self::CreateTickets,
            self::RespondToTickets,
            self::ManageTickets,
            self::ParticipateInBuildingGroups,
            self::ModerateBuildingGroups,
            self::ViewReports,
            self::ViewImports => false,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::OpenWorkspace => 'Open the organization\'s workspace (/o/{slug})',
            self::UpdateOrganization => 'Edit the organization profile and branding',
            self::ViewSettings => 'See the organization profile and branding settings',
            self::DeleteOrganization => 'Close the organization',
            self::ManageBilling => 'Pay the GoProperty subscription, change plan, offer the bill to another organization or take one over (billing transfers)',
            self::ManagePaymentSettings => 'Manage bank accounts and the organization\'s Paystack keys',
            self::ViewPaymentSettings => 'See bank accounts (masked) and the Paystack connection',
            self::ManageCompliance => 'Apply for a Transaction Tier and upload compliance documents',
            self::ViewCompliance => 'See the Transaction Tier application, its review and document expiry',
            self::ViewActivity => 'See the organization\'s activity log',
            self::ViewMembers => 'See members and their roles',
            self::ManageMembers => 'Invite members, change their roles, remove them',
            self::ViewOwners => 'See property owners\' details (ID numbers masked)',
            self::ManageOwners => 'Add, edit, archive and restore property owners',
            self::ViewProperties => 'See buildings and units',
            self::ManageProperties => 'Edit buildings and their photos, and their units',
            self::ManagePropertyPortfolio => 'Add, archive and restore buildings and units',
            self::AssignAgents => 'Choose which agents work on a building (an assigned agent sees only their buildings)',
            self::ViewTenants => 'See tenants\' details (ID numbers masked)',
            self::ManageTenants => 'Add, edit, archive and restore tenants',
            self::ManageTenantPortalAccess => 'Invite tenants to the portal, send or revoke their invitations, create one-time sign-in links and confirm their phone numbers in person',
            self::ViewLeases => 'See leases',
            self::ManageLeases => 'Create, renew and end leases',
            self::ViewBills => 'See bills',
            self::ManageBills => 'Issue and void bills',
            self::ViewUtilities => 'See utility types, which utilities each unit is charged and meter readings',
            self::ManageUtilities => 'Add, edit, archive and restore utility types, and choose which utilities each unit is charged, at what rate and cycle',
            self::RecordMeterReadings => 'Record meter readings of units\' metered utilities, and correct or remove the latest one before it is billed',
            self::ViewPayments => 'See payments',
            self::ConfirmPayments => 'Record, confirm and reject payments',
            self::SubmitPaymentProof => 'Upload proof of a payment',
            self::ViewStatements => 'See uploaded bank statements and their lines (account numbers masked)',
            self::ImportStatements => 'Upload bank statements (CSV or Excel) of the organization\'s bank accounts',
            self::MatchStatements => 'Match bank statement credits to tenants\' bills by hand (one credit split across bills too), look for matches again and confirm high-confidence matches in bulk',
            self::ApproveReceiptVoids => 'Approve another member\'s request to void a receipt (the second approver; voids a receipt alone only when no other owner or administrator exists)',
            self::ViewTickets => 'See maintenance tickets',
            self::CreateTickets => 'Raise maintenance tickets',
            self::RespondToTickets => 'Reply to maintenance tickets',
            self::ManageTickets => 'Assign tickets and change their status',
            self::ParticipateInBuildingGroups => 'Post and comment in Building Groups',
            self::ModerateBuildingGroups => 'Moderate Building Groups',
            self::ViewReports => 'See financial reports',
            self::ViewImports => 'See imports, their dry-run reports and error files',
            self::ManageImports => 'Import data from spreadsheets: upload, dry run, commit and roll back',
        };
    }
}
