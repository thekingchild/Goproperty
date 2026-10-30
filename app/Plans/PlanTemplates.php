<?php

declare(strict_types=1);

namespace App\Plans;

use App\Mail\Templates\Placeholder;
use App\Mail\Templates\TemplateCategory;
use App\Mail\Templates\TemplateContent;
use App\Mail\Templates\TemplateDefinition;

/**
 * The 30 days' notice of a plan change (P06-01a; BILLING_PAYMENTS.md
 * § 1.3): GoProperty's own email (platform branding), edited by
 * `content-manager` like every template. Transactional: always sent. The
 * in-app notice shows the subject.
 */
final class PlanTemplates
{
    public const string TERMS_CHANGING = 'plans.terms_changing';

    /** @return list<TemplateDefinition> */
    public static function definitions(): array
    {
        return [
            new TemplateDefinition(
                key: self::TERMS_CHANGING,
                name: 'Plan terms changing',
                description: "To an organization's owners and administrators, at least 30 days before its plan's prices or features change.",
                category: TemplateCategory::Transactional,
                placeholders: [
                    new Placeholder('recipient.name', "The owner's or administrator's name", 'Ada Okafor'),
                    new Placeholder('organization.name', "The organization's name", 'Adeyemi Realty Ltd'),
                    new Placeholder('plan.name', 'The plan', 'Growth'),
                    new Placeholder('change.date', 'When the new terms start, e.g. "1 February 2027"', '1 February 2027'),
                    new Placeholder('change.first_bill', 'When the organization first pays them: its first billing period from that date', '1 February 2027'),
                    new Placeholder('change.summary', 'What changes, one line each', "Base price: ₦35,000 → ₦40,000 a month\nEach extra unit: ₦500 → ₦600 a month"),
                    new Placeholder('bill.before', 'Its monthly bill at today\'s units, now (before VAT)', '₦40,000'),
                    new Placeholder('bill.after', 'The same bill on the new terms (before VAT)', '₦46,000'),
                ],
                content: new TemplateContent(
                    subject: 'Your {{ plan.name }} plan changes on {{ change.date }}',
                    body: "Hello {{ recipient.name }},\n\n"
                        ."From {{ change.date }} the {{ plan.name }} plan that {{ organization.name }} is on changes:\n\n"
                        ."{{ change.summary }}\n\n"
                        ."At today's units, {{ organization.name }}'s monthly bill goes from {{ bill.before }} to {{ bill.after }} before VAT. You keep today's terms until your first billing period that starts on or after {{ change.date }} ({{ change.first_bill }}).\n\n"
                        .'You can compare plans or move to another one in Settings → Subscription.',
                    actionLabel: 'See your subscription',
                ),
            ),
        ];
    }
}
