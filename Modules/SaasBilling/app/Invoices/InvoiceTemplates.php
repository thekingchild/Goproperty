<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Invoices;

use App\Mail\Templates\Placeholder;
use App\Mail\Templates\TemplateCategory;
use App\Mail\Templates\TemplateContent;
use App\Mail\Templates\TemplateDefinition;

/**
 * GoProperty's invoice email (P06-02; BILLING_PAYMENTS.md § 3 step 4) and
 * its payment receipt (P06-03, § 4.2 "receipt + notifications"): to the
 * owners of the organization (and of its payer, when another), in
 * GoProperty's branding, edited by `content-manager` like every template.
 * Transactional: always sent. The in-app notice shows the subject.
 */
final class InvoiceTemplates
{
    public const string INVOICE_ISSUED = 'billing.invoice_issued';

    public const string PAYMENT_RECEIVED = 'billing.payment_received';

    /** @return list<TemplateDefinition> */
    public static function definitions(): array
    {
        return [
            new TemplateDefinition(
                key: self::INVOICE_ISSUED,
                name: 'GoProperty invoice issued',
                description: "To an organization's owners when GoProperty invoices its subscription: at the end of the trial, then each billing day.",
                category: TemplateCategory::Transactional,
                placeholders: [
                    new Placeholder('recipient.name', "The owner's name", 'Ada Okafor'),
                    new Placeholder('organization.name', "The organization's name", 'Adeyemi Realty Ltd'),
                    new Placeholder('invoice.number', 'The invoice number', 'PR-2026-000123'),
                    new Placeholder('plan.name', 'The plan', 'Growth'),
                    new Placeholder('invoice.period', 'The billing period', '1–31 December 2026'),
                    new Placeholder('invoice.units', 'Units counted on the billing day', '60'),
                    new Placeholder('invoice.lines', 'The invoice lines, one a line: the base price, the extra units, the subtotal and VAT', "Growth plan, 50 units included (a month): ₦35,000\n10 extra units × ₦500 (a month): ₦5,000\nSubtotal: ₦40,000\nVAT (7.5%): ₦3,000"),
                    new Placeholder('invoice.total', 'The total, VAT included', '₦43,000'),
                    new Placeholder('invoice.due_date', 'The day it is due, e.g. "8 December 2026"', '8 December 2026'),
                ],
                content: new TemplateContent(
                    subject: 'GoProperty invoice {{ invoice.number }}: {{ invoice.total }} due {{ invoice.due_date }}',
                    body: "Hello {{ recipient.name }},\n\n"
                        ."Here is GoProperty's invoice {{ invoice.number }} to {{ organization.name }} for the {{ plan.name }} plan, {{ invoice.period }}, counting {{ invoice.units }} units.\n\n"
                        ."{{ invoice.lines }}\n\n"
                        ."Total: {{ invoice.total }}, due by {{ invoice.due_date }}.\n\n"
                        .'An owner can pay it by card from Settings → Subscription.',
                    actionLabel: 'See your invoices',
                ),
            ),
            new TemplateDefinition(
                key: self::PAYMENT_RECEIVED,
                name: 'GoProperty payment received',
                description: "To an organization's owners when GoProperty's Paystack account confirms a payment of its subscription invoice.",
                category: TemplateCategory::Transactional,
                placeholders: [
                    new Placeholder('recipient.name', "The owner's name", 'Ada Okafor'),
                    new Placeholder('organization.name', "The organization's name", 'Adeyemi Realty Ltd'),
                    new Placeholder('invoice.number', 'The invoice paid', 'PR-2026-000123'),
                    new Placeholder('invoice.period', 'Its billing period', '1–31 December 2026'),
                    new Placeholder('payment.amount', 'The amount paid', '₦43,000'),
                    new Placeholder('payment.date', 'When Paystack confirmed it, e.g. "3 December 2026"', '3 December 2026'),
                    new Placeholder('payment.reference', "Paystack's reference", 'PINV-PR-2026-000123-a1'),
                ],
                content: new TemplateContent(
                    subject: 'Payment received for GoProperty invoice {{ invoice.number }}',
                    body: "Hello {{ recipient.name }},\n\n"
                        ."Thank you: GoProperty received {{ payment.amount }} on {{ payment.date }} for invoice {{ invoice.number }} ({{ invoice.period }}), paid by {{ organization.name }}.\n\n"
                        .'Paystack reference: {{ payment.reference }}.',
                    actionLabel: 'See your invoices',
                ),
            ),
        ];
    }
}
