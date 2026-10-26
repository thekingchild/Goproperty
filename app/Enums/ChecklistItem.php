<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The setup checklist on the workspace dashboard (ONBOARDING_COMPLIANCE.md
 * § 2 step 7): steps 3–6 of signup plus branding. Each organization has one
 * row per item (`onboarding_checklist_items`); the feature that finishes an
 * item marks it done with App\Onboarding\OnboardingChecklist::complete().
 */
enum ChecklistItem: string
{
    use HasValues;

    case Tier = 'tier';
    case Plan = 'plan';
    case Collections = 'collections';
    case Data = 'data';
    case Branding = 'branding';

    public function label(): string
    {
        return match ($this) {
            self::Tier => 'Choose your Transaction Tier',
            self::Plan => 'Choose a plan',
            self::Collections => 'Set up rent collection',
            self::Data => 'Add your properties',
            self::Branding => 'Add your branding',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Tier => 'How much rent you will manage through the platform.',
            self::Plan => 'Your free trial runs on the plan you choose.',
            self::Collections => 'Add the bank account tenants pay into, or connect your own Paystack.',
            self::Data => 'Import from Excel with a dry run first, or add your first property.',
            self::Branding => 'Your logo, colours and letterhead on invoices and receipts.',
        };
    }

    /**
     * The page that finishes this item, once the feature exists. The
     * checklist links to it only when the route is registered, so later
     * tasks light up their item by naming the route here.
     */
    public function routeName(): string
    {
        return match ($this) {
            self::Tier => 'workspace.compliance.tier',
            self::Plan => 'workspace.billing',
            self::Collections => 'workspace.settings.collections',
            self::Data => 'workspace.imports.create',
            self::Branding => 'workspace.settings.branding',
        };
    }
}
