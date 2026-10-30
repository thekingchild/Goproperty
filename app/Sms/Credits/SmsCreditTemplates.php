<?php

declare(strict_types=1);

namespace App\Sms\Credits;

use App\Mail\Templates\Placeholder;
use App\Mail\Templates\TemplateCategory;
use App\Mail\Templates\TemplateContent;
use App\Mail\Templates\TemplateDefinition;

/**
 * The SMS credit notices to an agency's owners and administrators (P05-03):
 * GoProperty's own emails (platform branding), edited by `content-manager`
 * in the console like every template (PLATFORM_CONSOLE.md § 5).
 * Transactional: always sent. The in-app notice shows the subject.
 */
final class SmsCreditTemplates
{
    public const string LOW = 'sms_credits.low';

    public const string EMPTY = 'sms_credits.empty';

    /** @return list<TemplateDefinition> */
    public static function definitions(): array
    {
        $placeholders = [
            new Placeholder('recipient.name', "The owner's or administrator's name", 'Ada Okafor'),
            new Placeholder('organization.name', "The organization's name", 'Adeyemi Realty Ltd'),
            new Placeholder('credits.balance', 'Credits left', '100'),
            new Placeholder('credits.allowance', "This month's allowance", '500'),
            new Placeholder('credits.reset_date', 'When the next allowance arrives, e.g. "1 January 2027"', '1 January 2027'),
        ];

        return [
            new TemplateDefinition(
                key: self::LOW,
                name: 'SMS credits running low',
                description: "To the organization's owners and administrators, once a month, when its SMS credits fall to 20 % of the month's allowance.",
                category: TemplateCategory::Transactional,
                placeholders: $placeholders,
                content: new TemplateContent(
                    subject: 'SMS credits running low: {{ credits.balance }} left',
                    body: "Hello {{ recipient.name }},\n\n"
                        ."{{ organization.name }} has {{ credits.balance }} SMS credits left of this month's {{ credits.allowance }}. Each SMS reminder to a tenant uses one.\n\n"
                        ."When they run out, SMS reminders stop until {{ credits.reset_date }}, when next month's allowance arrives. Email and in-app reminders carry on.\n\n"
                        .'Buy more credits to keep texting your tenants.',
                    actionLabel: 'Buy SMS credits',
                ),
            ),
            new TemplateDefinition(
                key: self::EMPTY,
                name: 'SMS credits used up',
                description: "To the organization's owners and administrators, once a month, when its SMS credits reach zero.",
                category: TemplateCategory::Transactional,
                placeholders: $placeholders,
                content: new TemplateContent(
                    subject: 'SMS credits used up: SMS reminders have stopped',
                    body: "Hello {{ recipient.name }},\n\n"
                        ."{{ organization.name }} has used all its SMS credits, so no SMS reminders are being sent to tenants. Email and in-app reminders carry on.\n\n"
                        .'Next month\'s allowance arrives on {{ credits.reset_date }}. Buy credits now to start texting again at once.',
                    actionLabel: 'Buy SMS credits',
                ),
            ),
        ];
    }
}
