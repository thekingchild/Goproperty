<?php

declare(strict_types=1);

namespace App\Plans;

use App\Enums\Concerns\HasValues;

/**
 * The feature registry (P06-01a; BILLING_PAYMENTS.md § 1.2, ARCHITECTURE.md
 * § 6.10): what a plan version switches on or limits. A plan stores one
 * `plan_feature` row per case; an organization's override wins over it
 * (App\Plans\Features). A version saved before a feature existed has no
 * row for it and gets default().
 */
enum Feature: string
{
    use HasValues;

    case OwnPaystack = 'own_paystack';
    case WhatsApp = 'whatsapp';
    case BuildingGroup = 'building_group';
    case AgentAssignment = 'agent_assignment';
    case ApprovalWorkflows = 'approval_workflows';
    case RemovePoweredBy = 'remove_powered_by';
    case AssistedImport = 'assisted_import';
    case StaffUsers = 'staff_users';
    case SmsCredits = 'sms_credits';

    /** A limit (a number, null = unlimited) rather than an on/off switch. */
    public function isLimit(): bool
    {
        return in_array($this, [self::StaffUsers, self::SmsCredits], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::OwnPaystack => 'Agency\'s own Paystack (online payments)',
            self::WhatsApp => 'WhatsApp notifications',
            self::BuildingGroup => 'Building Group',
            self::AgentAssignment => 'Agent ↔ building assignment',
            self::ApprovalWorkflows => 'Approval workflows',
            self::RemovePoweredBy => 'Remove "Generated with" from documents',
            self::AssistedImport => 'Assisted data import by staff',
            self::StaffUsers => 'Staff users',
            self::SmsCredits => 'SMS credits included each month',
        };
    }

    /** Short words for an upgrade message: "… is on the Growth plan and above." */
    public function shortLabel(): string
    {
        return match ($this) {
            self::OwnPaystack => 'Online payments through your own Paystack',
            self::WhatsApp => 'WhatsApp notifications',
            self::BuildingGroup => 'Building Group',
            self::AgentAssignment => 'Assigning agents to buildings',
            self::ApprovalWorkflows => 'Approval workflows',
            self::RemovePoweredBy => 'Removing the "Generated with" line',
            self::AssistedImport => 'Assisted import',
            self::StaffUsers => 'Staff users',
            self::SmsCredits => 'SMS credits',
        };
    }

    /**
     * When a version has no row for the feature (added to the registry
     * after the version was saved): switches off, limits as below.
     */
    public function default(): FeatureValue
    {
        return match ($this) {
            self::StaffUsers => FeatureValue::limit(3),
            self::SmsCredits => FeatureValue::limit(0),
            default => FeatureValue::off(),
        };
    }
}
