<?php

declare(strict_types=1);

namespace App\Platform\Staff;

use App\Enums\Concerns\HasValues;
use App\Platform\Staff\StaffPermission as P;

/**
 * The roles GoProperty ships with (PLATFORM_CONSOLE.md § 1, default roles
 * table; impersonation grants from § 6). They are data once seeded: a
 * super-admin may change their permissions and descriptions (not their
 * names, which the two-person rules and later tasks refer to), except
 * `super-admin`, which always holds the whole catalogue.
 */
enum DefaultStaffRole: string
{
    use HasValues;

    case SuperAdmin = 'super-admin';
    case OperationsManager = 'operations-manager';
    case ComplianceOfficer = 'compliance-officer';
    case FinanceAdmin = 'finance-admin';
    case SupportAgent = 'support-agent';
    case TechnicalAdmin = 'technical-admin';
    case ContentManager = 'content-manager';
    case Analyst = 'analyst';

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Everything, including staff management, role grants, production restore approval and system settings. Still subject to two-person rules.',
            self::OperationsManager => 'Customers, organization suspend/reactivate, plan changes, grace extensions, all dashboards.',
            self::ComplianceOfficer => 'Transaction Tier applications and documents, tier decisions, compliance flags, compliance restrictions.',
            self::FinanceAdmin => 'Plans and prices, platform invoices, bank-transfer confirmations, refunds, credit notes.',
            self::SupportAgent => 'Customer lookup, read-only impersonation with a reason, resending emails and invoices, delivery logs.',
            self::TechnicalAdmin => 'Operations dashboard, delivery logs, queues, webhook replay, backups (run, download, verify, restore to staging), email provider settings.',
            self::ContentManager => 'Email templates, marketing page content, announcements, platform theme and website text.',
            self::Analyst => 'Analytics dashboard and reports (aggregated, no personal data).',
        };
    }

    /**
     * Granting these needs a second staff member's approval
     * (PLATFORM_CONSOLE.md § 1, two-person rules).
     */
    public function needsTwoPersonGrant(): bool
    {
        return in_array($this, [self::SuperAdmin, self::FinanceAdmin, self::ComplianceOfficer], true);
    }

    /** @return list<P> */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => P::cases(),
            self::OperationsManager => [
                P::CustomersView, P::CustomersSuspend, P::CustomersChangePlan, P::CustomersExtendGrace,
                P::ImpersonateReadOnly, P::ImpersonationViewLog,
                P::OperationsView, P::AnalyticsView, P::DeliveryLogsView,
            ],
            self::ComplianceOfficer => [
                P::CustomersView, P::ComplianceViewDocuments, P::TiersReview, P::TiersApprove, P::TiersConfigure,
                P::ComplianceFlags, P::ComplianceRestrict, P::ImpersonationViewLog,
            ],
            self::FinanceAdmin => [
                P::CustomersView, P::PlansManage, P::InvoicesManage, P::PaymentsConfirmTransfer,
                P::RefundsCreate, P::RefundsApprove, P::CreditNotesCreate,
            ],
            self::SupportAgent => [P::CustomersView, P::ImpersonateReadOnly, P::EmailsResend, P::DeliveryLogsView],
            self::TechnicalAdmin => [
                P::OperationsView, P::SystemStatus, P::QueuesManage, P::WebhooksReplay,
                P::BackupsRun, P::BackupsDownload, P::BackupsVerify, P::BackupsRestoreStaging, P::BackupsRestoreProduction,
                P::BackupDestinations, P::EmailProviderSettings,
                // The operations dashboard's Messaging figures open the delivery log (P05-05).
                P::DeliveryLogsView,
            ],
            self::ContentManager => [P::TemplatesEdit, P::MarketingEdit, P::AnnouncementsManage, P::SettingsTheme, P::SettingsSite, P::SettingsSeo],
            self::Analyst => [P::AnalyticsView],
        };
    }
}
