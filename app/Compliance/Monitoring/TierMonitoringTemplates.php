<?php

declare(strict_types=1);

namespace App\Compliance\Monitoring;

use App\Mail\Templates\Placeholder;
use App\Mail\Templates\TemplateCategory;
use App\Mail\Templates\TemplateContent;
use App\Mail\Templates\TemplateDefinition;

/**
 * What the Transaction Tier monitoring tells an organization's owners
 * (P06-09; ONBOARDING_COMPLIANCE.md § 5.4–5.5, default 94): GoProperty's
 * own emails (platform branding), edited by `content-manager` in the
 * console like every template (PLATFORM_CONSOLE.md § 5). Transactional:
 * always sent. The in-app notice shows the subject.
 */
final class TierMonitoringTemplates
{
    public const string VOLUME_80 = 'tier_monitoring.volume_80';

    public const string FORECAST_OVER = 'tier_monitoring.forecast_over';

    public const string VOLUME_100 = 'tier_monitoring.volume_100';

    public const string SINGLE_OVER = 'tier_monitoring.single_over';

    public const string CASH_THRESHOLD = 'tier_monitoring.cash_threshold';

    public const string RESTRICTED = 'tier_monitoring.restricted';

    public const string RESTRICTION_LIFTED = 'tier_monitoring.restriction_lifted';

    /** @return list<TemplateDefinition> */
    public static function definitions(): array
    {
        $recipient = new Placeholder('recipient.name', "The owner's name", 'Ada Okafor');
        $organization = new Placeholder('organization.name', "The organization's name", 'Adeyemi Realty Ltd');
        $tier = new Placeholder('tier.name', "The organization's Transaction Tier", 'Tier 1 — Standard');
        $volume = [
            new Placeholder('volume.amount', 'Collected over the last 12 months', '₦42,000,000'),
            new Placeholder('volume.limit', 'The yearly limit', '₦50,000,000'),
            new Placeholder('volume.percent', 'The share of the limit used, e.g. "84 %"', '84 %'),
        ];
        $payment = [
            new Placeholder('payment.amount', 'The payment', '₦6,000,000'),
            new Placeholder('payment.limit', 'The limit or threshold it reached', '₦5,000,000'),
            new Placeholder('payment.date', 'The day it was paid, e.g. "3 November 2026"', '3 November 2026'),
        ];

        return [
            new TemplateDefinition(
                key: self::VOLUME_80,
                name: 'Transaction Tier: approaching the yearly limit',
                description: 'To the owners when the last 12 months of collections reach 80 % of the yearly limit.',
                category: TemplateCategory::Transactional,
                placeholders: [$recipient, $organization, $tier, ...$volume],
                content: new TemplateContent(
                    subject: '{{ organization.name }} is approaching its Transaction Tier limit',
                    body: "Hello {{ recipient.name }},\n\n"
                        ."{{ organization.name }} has collected {{ volume.amount }} from tenants over the last 12 months: {{ volume.percent }} of the {{ volume.limit }} a year allowed on {{ tier.name }}.\n\n"
                        ."Apply for a higher tier now so nothing is held up when you reach the limit. Your tenants' payments are never blocked.",
                    actionLabel: 'Upgrade tier',
                ),
            ),
            new TemplateDefinition(
                key: self::FORECAST_OVER,
                name: 'Transaction Tier: forecast over the yearly limit',
                description: "To the owners when the rent the organization's leases will bill over the next 12 months is above the yearly limit.",
                category: TemplateCategory::Transactional,
                placeholders: [$recipient, $organization, $tier, new Placeholder('forecast.amount', 'Rent the leases will bill over the next 12 months', '₦58,000,000'), $volume[1]],
                content: new TemplateContent(
                    subject: '{{ organization.name }} is on course to pass its Transaction Tier limit',
                    body: "Hello {{ recipient.name }},\n\n"
                        ."The leases on GoProperty will bill about {{ forecast.amount }} over the next 12 months, more than the {{ volume.limit }} a year allowed on {{ tier.name }}.\n\n"
                        .'Applying for a higher tier early means it can be reviewed before you reach the limit.',
                    actionLabel: 'Upgrade tier',
                ),
            ),
            new TemplateDefinition(
                key: self::VOLUME_100,
                name: 'Transaction Tier: yearly limit reached',
                description: 'To the owners when the last 12 months of collections reach the yearly limit: an upgrade is required before the grace period ends.',
                category: TemplateCategory::Transactional,
                placeholders: [$recipient, $organization, $tier, ...$volume,
                    new Placeholder('grace.date', 'The day the grace period ends, e.g. "1 December 2026"', '1 December 2026'),
                    new Placeholder('limit.note', 'A sentence when the limit was lowered recently (may be empty)', 'GoProperty lowered this limit on 1 November 2026.'),
                ],
                content: new TemplateContent(
                    subject: 'Upgrade required: {{ organization.name }} has reached its Transaction Tier limit',
                    body: "Hello {{ recipient.name }},\n\n"
                        ."{{ organization.name }} has collected {{ volume.amount }} from tenants over the last 12 months, over the {{ volume.limit }} a year allowed on {{ tier.name }}. {{ limit.note }}\n\n"
                        ."Apply for a higher tier by {{ grace.date }}. If no application has been sent by then, the organization will be restricted: no new leases, buildings or units, and online payment paused. Bills, payments, receipts and your tenants' portal keep working.",
                    actionLabel: 'Upgrade tier',
                ),
            ),
            new TemplateDefinition(
                key: self::SINGLE_OVER,
                name: 'Transaction Tier: single payment over the limit',
                description: "To the owners when one tenant payment is above the tier's single-payment limit.",
                category: TemplateCategory::Transactional,
                placeholders: [$recipient, $organization, $tier, ...$payment],
                content: new TemplateContent(
                    subject: '{{ organization.name }}: a payment above your Transaction Tier limit',
                    body: "Hello {{ recipient.name }},\n\n"
                        ."A tenant payment of {{ payment.amount }} paid on {{ payment.date }} is above the {{ payment.limit }} single-payment limit of {{ tier.name }}. GoProperty's compliance team has been told.\n\n"
                        .'If payments this large are normal for you, apply for a higher tier.',
                    actionLabel: 'Upgrade tier',
                ),
            ),
            new TemplateDefinition(
                key: self::CASH_THRESHOLD,
                name: 'Transaction Tier: cash payment at the reporting threshold',
                description: 'To the owners when a cash payment reaches the MLPPA 2022 threshold (₦5m from a person, ₦10m from a company): reporting it is the agency\'s duty.',
                category: TemplateCategory::Transactional,
                placeholders: [$recipient, $organization, ...$payment],
                content: new TemplateContent(
                    subject: '{{ organization.name }}: a cash payment you may need to report',
                    body: "Hello {{ recipient.name }},\n\n"
                        ."A cash payment of {{ payment.amount }} paid on {{ payment.date }} reached the {{ payment.limit }} cash threshold of the Money Laundering (Prevention and Prohibition) Act 2022.\n\n"
                        .'As the agency you may need to report it. GoProperty does not report it for you; its records and exports are there to help.',
                    actionLabel: 'Open your Transaction Tier',
                ),
            ),
            new TemplateDefinition(
                key: self::RESTRICTED,
                name: 'Transaction Tier: organization restricted',
                description: 'To the owners when the organization is restricted: the grace period after reaching the yearly limit ended without an upgrade application, or GoProperty compliance restricted it.',
                category: TemplateCategory::Transactional,
                placeholders: [$recipient, $organization, new Placeholder('restriction.reason', 'Why, in one sentence', 'The grace period after reaching your yearly limit ended without an upgrade application.')],
                content: new TemplateContent(
                    subject: '{{ organization.name }} is restricted',
                    body: "Hello {{ recipient.name }},\n\n"
                        ."{{ restriction.reason }}\n\n"
                        ."Until it is lifted, {{ organization.name }} cannot add leases, buildings or units, and online payment is paused. Existing bills, recording and confirming payments, receipts and your tenants' portal keep working.\n\n"
                        .'Apply for a higher tier to lift it: an organization whose application is being reviewed is not restricted.',
                    actionLabel: 'Upgrade tier',
                ),
            ),
            new TemplateDefinition(
                key: self::RESTRICTION_LIFTED,
                name: 'Transaction Tier: restriction lifted',
                description: 'To the owners when the restriction is lifted.',
                category: TemplateCategory::Transactional,
                placeholders: [$recipient, $organization],
                content: new TemplateContent(
                    subject: '{{ organization.name }} is no longer restricted',
                    body: "Hello {{ recipient.name }},\n\n"
                        .'{{ organization.name }} can add leases, buildings and units again, and online payment is back on.',
                    actionLabel: 'Open your Transaction Tier',
                ),
            ),
        ];
    }
}
