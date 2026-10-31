<?php

declare(strict_types=1);

namespace App\Platform\Staff;

use App\Enums\Concerns\HasValues;

/**
 * The staff permission catalogue (PLATFORM_CONSOLE.md § 1): one permission
 * per console area and action, on the `staff` guard. New features add
 * permissions here, never roles; StaffRolesSeeder creates them and gives
 * them to the default roles that should have them.
 *
 * Protected permissions can only be added to a role by a `super-admin`,
 * and adding one needs a second super-admin's approval.
 */
enum StaffPermission: string
{
    use HasValues;

    public const string GUARD = 'staff';

    // Customers
    case CustomersView = 'customers.view';
    case CustomersSuspend = 'customers.suspend';
    case CustomersChangePlan = 'customers.change_plan';
    case CustomersExtendGrace = 'customers.extend_grace';
    case CustomersOverrideFeatures = 'customers.override_features';

    // Impersonation (§ 6)
    case ImpersonateReadOnly = 'impersonate.read_only';
    case ImpersonateWrite = 'impersonate.write';
    case ImpersonationViewLog = 'impersonation.view_log';

    // Compliance and Transaction Tiers
    case ComplianceViewDocuments = 'compliance.view_documents';
    case TiersReview = 'tiers.review';
    case TiersApprove = 'tiers.approve';
    case TiersConfigure = 'tiers.configure';
    case TiersApproveSettings = 'tiers.approve_settings';
    case TiersOverride = 'tiers.override';
    case ComplianceFlags = 'compliance.flags';
    case ComplianceRestrict = 'compliance.restrict';

    // Billing
    case PlansManage = 'plans.manage';
    case InvoicesManage = 'invoices.manage';
    case PaymentsConfirmTransfer = 'payments.confirm_transfer';
    case RefundsCreate = 'refunds.create';
    case RefundsApprove = 'refunds.approve';
    case RefundsConfigure = 'refunds.configure';
    case CreditNotesCreate = 'credit_notes.create';

    // Support
    case EmailsResend = 'emails.resend';
    case DeliveryLogsView = 'delivery_logs.view';

    // Operations (§ 2, § 4)
    case OperationsView = 'operations.view';
    case SystemStatus = 'system.status';
    case QueuesManage = 'queues.manage';
    case WebhooksReplay = 'webhooks.replay';
    case BackupsRun = 'backups.run';
    case BackupsDownload = 'backups.download';
    case BackupsVerify = 'backups.verify';
    case BackupsRestoreStaging = 'backups.restore_staging';
    case BackupsRestoreProduction = 'backups.restore_production';
    case BackupsApproveProductionRestore = 'backups.approve_production_restore';
    case BackupDestinations = 'backups.destinations';
    case EmailProviderSettings = 'settings.email_provider';

    // Content (§ 5)
    case TemplatesEdit = 'templates.edit';
    case MarketingEdit = 'marketing.edit';
    case AnnouncementsManage = 'announcements.manage';

    // Analytics (§ 3)
    case AnalyticsView = 'analytics.view';

    // Platform settings (§ 9): the keys in config/settings.php name these.
    case SettingsTheme = 'settings.theme';
    case SettingsSite = 'settings.site';
    case SettingsSeo = 'settings.seo';
    case SettingsCustomCode = 'settings.custom_code';
    case SettingsSecurity = 'settings.security';

    // Staff, roles and approvals (§ 1)
    case StaffView = 'staff.view';
    case StaffInvite = 'staff.invite';
    case StaffManage = 'staff.manage';
    case RolesManage = 'roles.manage';
    case ApprovalsView = 'approvals.view';

    // Audit log (§ 7) and access log (§ 8)
    case AuditView = 'audit.view';
    case AccessLogView = 'access_log.view';

    // NDPA erasure
    case DataPurge = 'data.purge';

    /**
     * Impersonation write mode, production restore approval, role
     * management and data purge (PLATFORM_CONSOLE.md § 1).
     */
    public function isProtected(): bool
    {
        return in_array($this, [self::ImpersonateWrite, self::BackupsApproveProductionRestore, self::RolesManage, self::DataPurge], true);
    }

    public function area(): string
    {
        return match (explode('.', $this->value)[0]) {
            'customers' => 'Customers',
            'impersonate', 'impersonation' => 'Impersonation',
            'compliance', 'tiers' => 'Compliance',
            'plans', 'invoices', 'payments', 'refunds', 'credit_notes' => 'Billing',
            'emails', 'delivery_logs' => 'Support',
            'operations', 'system', 'queues', 'webhooks', 'backups' => 'Operations',
            'templates', 'marketing', 'announcements' => 'Content',
            'analytics' => 'Analytics',
            'settings' => $this === self::EmailProviderSettings ? 'Operations' : 'Platform settings',
            'staff', 'roles', 'approvals' => 'Staff',
            'audit', 'access_log' => 'Audit',
            default => 'Data',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CustomersView => 'Look up customers',
            self::CustomersSuspend => 'Suspend and reactivate organizations',
            self::CustomersChangePlan => 'Change a customer\'s plan',
            self::CustomersExtendGrace => 'Extend a grace period',
            self::CustomersOverrideFeatures => 'Grant or withhold plan features for a customer',
            self::ImpersonateReadOnly => 'Impersonate a user (read-only, with reason)',
            self::ImpersonateWrite => 'Impersonate a user in write mode (needs approval)',
            self::ImpersonationViewLog => 'Read the impersonation log',
            self::ComplianceViewDocuments => 'View compliance documents',
            self::TiersReview => 'Review Transaction Tier applications',
            self::TiersApprove => 'Approve or reject Transaction Tiers',
            self::TiersConfigure => 'Propose changes to Transaction Tier limits and documents',
            self::TiersApproveSettings => 'Approve changes to Transaction Tier limits and documents',
            self::TiersOverride => 'Set custom Transaction Tier limits for one organization',
            self::ComplianceFlags => 'Work the compliance flag queue',
            self::ComplianceRestrict => 'Restrict organizations for compliance',
            self::PlansManage => 'Plans and prices',
            self::InvoicesManage => 'Platform invoices',
            self::PaymentsConfirmTransfer => 'Confirm bank-transfer subscription payments',
            self::RefundsCreate => 'Request refunds',
            self::RefundsApprove => 'Approve refunds',
            self::RefundsConfigure => 'Set the amount above which refunds need approval',
            self::CreditNotesCreate => 'Issue credit notes',
            self::EmailsResend => 'Resend emails and invoices',
            self::DeliveryLogsView => 'View delivery logs',
            self::OperationsView => 'Operations dashboard',
            self::SystemStatus => 'System status and heartbeats',
            self::QueuesManage => 'Queues and failed jobs',
            self::WebhooksReplay => 'Replay webhooks',
            self::BackupsRun => 'Run backups',
            self::BackupsDownload => 'Download backups',
            self::BackupsVerify => 'Verify backups',
            self::BackupsRestoreStaging => 'Restore a backup to staging',
            self::BackupsRestoreProduction => 'Request a restore to production',
            self::BackupsApproveProductionRestore => 'Approve a restore to production',
            self::BackupDestinations => 'Backup destinations',
            self::EmailProviderSettings => 'Email provider settings',
            self::TemplatesEdit => 'Email templates',
            self::MarketingEdit => 'Marketing page content',
            self::AnnouncementsManage => 'Announcements',
            self::AnalyticsView => 'Analytics dashboard and reports',
            self::SettingsTheme => 'Brand and appearance',
            self::SettingsSite => 'Public website text',
            self::SettingsSeo => 'Search and sharing (SEO)',
            self::SettingsCustomCode => 'Custom code on public pages',
            self::SettingsSecurity => 'Sign-in security',
            self::StaffView => 'View staff',
            self::StaffInvite => 'Invite staff',
            self::StaffManage => 'Edit, deactivate and reactivate staff',
            self::RolesManage => 'Manage roles and grant them to staff',
            self::ApprovalsView => 'See every approval request',
            self::AuditView => 'Read the audit log',
            self::AccessLogView => 'Read the sign-in (access) log',
            self::DataPurge => 'Delete or purge an organization\'s data (NDPA)',
        };
    }

    /** @return list<self> */
    public static function protectedCases(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $permission): bool => $permission->isProtected()));
    }

    /**
     * Catalogue options grouped by area, for the role editor.
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::cases() as $permission) {
            $groups[$permission->area()][$permission->value] = $permission->label().($permission->isProtected() ? ' (protected)' : '');
        }

        return $groups;
    }
}
