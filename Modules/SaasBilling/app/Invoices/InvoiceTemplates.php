<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Invoices;

use App\Mail\Templates\Placeholder;
use App\Mail\Templates\TemplateCategory;
use App\Mail\Templates\TemplateContent;
use App\Mail\Templates\TemplateDefinition;

/**
 * GoProperty's invoice email (P06-02; BILLING_PAYMENTS.md § 3 step 4), its
 * payment receipt (P06-03, § 4.2 "receipt + notifications"; also for a bank
 * transfer staff confirmed, P06-04) and the notice that a bank-transfer
 * proof was not accepted (P06-04, § 5 step 3): to the
 * owners of the organization (and of its payer, when another), in
 * GoProperty's branding, edited by `content-manager` like every template.
 * Transactional: always sent. The in-app notice shows the subject.
 */
final class InvoiceTemplates
{
    public const string INVOICE_ISSUED = 'billing.invoice_issued';

    public const string PAYMENT_RECEIVED = 'billing.payment_received';

    public const string TRANSFER_REJECTED = 'billing.transfer_rejected';

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
                    new Placeholder('bank_transfer', "How to pay by bank transfer: GoProperty's account and the narration to use (empty until GoProperty's account is set)", 'Or pay by bank transfer to Zenith Bank, account 1012345678 (GoProperty Technologies Ltd) with PR-2026-000123 as the narration, then send the proof from Settings → Subscription.'),
                ],
                content: new TemplateContent(
                    subject: 'GoProperty invoice {{ invoice.number }}: {{ invoice.total }} due {{ invoice.due_date }}',
                    body: "Hello {{ recipient.name }},\n\n"
                        ."Here is GoProperty's invoice {{ invoice.number }} to {{ organization.name }} for the {{ plan.name }} plan, {{ invoice.period }}, counting {{ invoice.units }} units.\n\n"
                        ."{{ invoice.lines }}\n\n"
                        ."Total: {{ invoice.total }}, due by {{ invoice.due_date }}.\n\n"
                        ."An owner can pay it by card from Settings → Subscription.\n\n"
                        .'{{ bank_transfer }}',
                    actionLabel: 'See your invoices',
                ),
            ),
            new TemplateDefinition(
                key: self::PAYMENT_RECEIVED,
                name: 'GoProperty payment received',
                description: "To an organization's owners when GoProperty's Paystack account confirms a payment of its subscription invoice, or GoProperty's staff confirm a bank transfer for it.",
                category: TemplateCategory::Transactional,
                placeholders: [
                    new Placeholder('recipient.name', "The owner's name", 'Ada Okafor'),
                    new Placeholder('organization.name', "The organization's name", 'Adeyemi Realty Ltd'),
                    new Placeholder('invoice.number', 'The invoice paid', 'PR-2026-000123'),
                    new Placeholder('invoice.period', 'Its billing period', '1–31 December 2026'),
                    new Placeholder('payment.amount', 'The amount paid', '₦43,000'),
                    new Placeholder('payment.date', 'When it was confirmed, e.g. "3 December 2026"', '3 December 2026'),
                    new Placeholder('payment.reference', 'Paystack\'s reference, or "Bank transfer" for a transfer GoProperty\'s staff confirmed', 'PINV-PR-2026-000123-a1'),
                ],
                content: new TemplateContent(
                    subject: 'Payment received for GoProperty invoice {{ invoice.number }}',
                    body: "Hello {{ recipient.name }},\n\n"
                        ."Thank you: GoProperty received {{ payment.amount }} on {{ payment.date }} for invoice {{ invoice.number }} ({{ invoice.period }}), paid by {{ organization.name }}.\n\n"
                        .'Reference: {{ payment.reference }}.',
                    actionLabel: 'See your invoices',
                ),
            ),
            new TemplateDefinition(
                key: self::TRANSFER_REJECTED,
                name: 'GoProperty bank transfer not accepted',
                description: "To an organization's owners when GoProperty's staff could not accept the proof of a bank transfer sent for a subscription invoice.",
                category: TemplateCategory::Transactional,
                placeholders: [
                    new Placeholder('recipient.name', "The owner's name", 'Ada Okafor'),
                    new Placeholder('organization.name', "The organization's name", 'Adeyemi Realty Ltd'),
                    new Placeholder('invoice.number', 'The invoice', 'PR-2026-000123'),
                    new Placeholder('invoice.total', 'What the invoice asks, VAT included', '₦43,000'),
                    new Placeholder('payment.amount', 'The amount the proof said was sent', '₦40,000'),
                    new Placeholder('payment.date', 'The day the proof said it was sent', '2 December 2026'),
                    new Placeholder('rejection.reason', "GoProperty's reason", 'Only ₦40,000 reached our account; ₦43,000 is due.'),
                ],
                content: new TemplateContent(
                    subject: 'Your bank transfer proof for GoProperty invoice {{ invoice.number }} was not accepted',
                    body: "Hello {{ recipient.name }},\n\n"
                        ."GoProperty checked the proof of a {{ payment.amount }} bank transfer on {{ payment.date }} that {{ organization.name }} sent for invoice {{ invoice.number }}, and could not accept it:\n\n"
                        ."{{ rejection.reason }}\n\n"
                        .'Invoice {{ invoice.number }} for {{ invoice.total }} is still open. An owner can pay it by card, or send another proof, from Settings → Subscription.',
                    actionLabel: 'See your invoices',
                ),
            ),
        ];
    }
}
