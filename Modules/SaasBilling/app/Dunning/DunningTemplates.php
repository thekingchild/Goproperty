<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Dunning;

use App\Mail\Templates\Placeholder;
use App\Mail\Templates\TemplateCategory;
use App\Mail\Templates\TemplateChannel;
use App\Mail\Templates\TemplateContent;
use App\Mail\Templates\TemplateDefinition;
use Modules\SaasBilling\Enums\DunningStep;

/**
 * GoProperty's notices about an unpaid subscription (P06-05;
 * BILLING_PAYMENTS.md § 6): a failed payment, and each step of the dunning
 * ladder (DunningLadder), to the owners of the organization (and of its
 * payer), in GoProperty's branding; by SMS too on suspension and its
 * reminders. Edited by `content-manager` like every template.
 * Transactional: always sent. The in-app notice shows the subject.
 */
final class DunningTemplates
{
    public const string PAYMENT_FAILED = 'billing.payment_failed';

    /** @return list<TemplateDefinition> */
    public static function definitions(): array
    {
        $recipient = new Placeholder('recipient.name', "The owner's name", 'Ada Okafor');
        $organization = new Placeholder('organization.name', "The organization's name", 'Adeyemi Realty Ltd');
        $numbers = new Placeholder('invoice.numbers', 'The overdue invoices, e.g. "PR-2026-000123, PR-2026-000140"', 'PR-2026-000123');
        $count = new Placeholder('invoice.count', 'How many invoices are overdue', '1');
        $amount = new Placeholder('amount.overdue', 'What is overdue, VAT included', '₦43,000');
        $suspension = new Placeholder('suspension.date', 'The day the organization is suspended if nothing is paid, e.g. "8 December 2026"', '8 December 2026');
        $daysLeft = new Placeholder('days.left', 'Days until then, e.g. "3 days"', '3 days');
        $closing = new Placeholder('closing.date', 'The day the subscription is cancelled if nothing is paid, e.g. "7 March 2027"', '7 March 2027');
        $ladder = [$recipient, $organization, $numbers, $count, $amount];

        return [
            new TemplateDefinition(
                key: self::PAYMENT_FAILED,
                name: 'GoProperty payment failed',
                description: "To an organization's owners when a payment of its GoProperty invoice through Paystack fails. Nothing is charged again by itself.",
                category: TemplateCategory::Transactional,
                placeholders: [
                    $recipient, $organization,
                    new Placeholder('invoice.number', 'The invoice', 'PR-2026-000123'),
                    new Placeholder('invoice.total', 'What the invoice asks, VAT included', '₦43,000'),
                    new Placeholder('invoice.due_date', 'The day it is due, e.g. "8 December 2026"', '8 December 2026'),
                    new Placeholder('payment.amount', 'The amount of the payment that failed', '₦43,000'),
                    new Placeholder('payment.reference', "Paystack's reference", 'PINV-PR-2026-000123-a1'),
                ],
                content: new TemplateContent(
                    subject: 'Your payment for GoProperty invoice {{ invoice.number }} did not go through',
                    body: "Hello {{ recipient.name }},\n\n"
                        ."The payment of {{ payment.amount }} for {{ organization.name }}'s GoProperty invoice {{ invoice.number }} did not go through, so nothing was taken (reference {{ payment.reference }}).\n\n"
                        .'Invoice {{ invoice.number }} for {{ invoice.total }} is still open, due by {{ invoice.due_date }}. An owner can pay it now by card, or by bank transfer, from Settings → Subscription.',
                    actionLabel: 'Pay now',
                ),
            ),
            self::email(DunningStep::PastDue, 'GoProperty subscription overdue', 'The day after an invoice was due and is still unpaid: the subscription is past due.', [...$ladder, $suspension], new TemplateContent(
                subject: 'Your GoProperty subscription is overdue: {{ amount.overdue }} unpaid',
                body: "Hello {{ recipient.name }},\n\n"
                    ."{{ organization.name }}'s GoProperty invoice {{ invoice.numbers }} is unpaid past its due date: {{ amount.overdue }} is overdue.\n\n"
                    ."Pay it now to keep everything working. If it is still unpaid on {{ suspension.date }}, {{ organization.name }} will be suspended: members can still sign in, view and export, but nothing can be changed until it is paid. Nothing is deleted.\n\n"
                    .'An owner can pay by card, or by bank transfer, from Settings → Subscription.',
                actionLabel: 'Pay now',
            )),
            self::email(DunningStep::GraceWarning, 'GoProperty subscription: final days before suspension', 'Every day of the 7-day grace period, from 7 days past due until suspension.', [...$ladder, $suspension, $daysLeft], new TemplateContent(
                subject: '{{ organization.name }} will be suspended on {{ suspension.date }} unless {{ amount.overdue }} is paid',
                body: "Hello {{ recipient.name }},\n\n"
                    ."{{ organization.name }}'s GoProperty subscription is still unpaid: {{ amount.overdue }} is overdue (invoice {{ invoice.numbers }}).\n\n"
                    ."These are the final days before suspension. In {{ days.left }}, on {{ suspension.date }}, {{ organization.name }} becomes read-only: members can still sign in, view and export, but nothing can be changed until it is paid. Nothing is deleted.\n\n"
                    .'An owner can pay now by card, or by bank transfer, from Settings → Subscription.',
                actionLabel: 'Pay now',
            )),
            self::email(DunningStep::Suspended, 'GoProperty subscription suspended', 'When an unpaid subscription is suspended, 14 days past due: the organization becomes read-only. Also sent by SMS.', [...$ladder, $closing], new TemplateContent(
                subject: '{{ organization.name }} is suspended: {{ amount.overdue }} unpaid',
                body: "Hello {{ recipient.name }},\n\n"
                    ."{{ organization.name }}'s GoProperty subscription is unpaid beyond its grace period ({{ amount.overdue }}, invoice {{ invoice.numbers }}), so it is suspended. Members can still sign in, view and export everything, but nothing can be changed. Nothing is deleted.\n\n"
                    ."Pay now from Settings → Subscription, by card or bank transfer, and everything works again at once.\n\n"
                    .'If it is still unpaid on {{ closing.date }}, the subscription will be cancelled.',
                actionLabel: 'Pay now',
            )),
            self::email(DunningStep::SuspensionReminder, 'GoProperty subscription still suspended', 'Every 14 days while the subscription is suspended. Also sent by SMS.', [...$ladder, $closing], new TemplateContent(
                subject: 'Reminder: {{ organization.name }} is still suspended, {{ amount.overdue }} unpaid',
                body: "Hello {{ recipient.name }},\n\n"
                    ."{{ organization.name }} is still suspended because its GoProperty subscription is unpaid: {{ amount.overdue }} (invoice {{ invoice.numbers }}). Its data is safe and can still be viewed and exported.\n\n"
                    ."Pay from Settings → Subscription, by card or bank transfer, and everything works again at once.\n\n"
                    .'If it is still unpaid on {{ closing.date }}, the subscription will be cancelled.',
                actionLabel: 'Pay now',
            )),
            self::email(DunningStep::Cancelled, 'GoProperty subscription cancelled', 'When a subscription suspended for 90 days is cancelled for non-payment.', $ladder, new TemplateContent(
                subject: "{{ organization.name }}'s GoProperty subscription is cancelled",
                body: "Hello {{ recipient.name }},\n\n"
                    ."{{ organization.name }}'s GoProperty subscription was suspended for 90 days without payment ({{ amount.overdue }}, invoice {{ invoice.numbers }}), so it is now cancelled and the organization is closed.\n\n"
                    .'Its data can still be viewed and exported, and is kept for 90 days. To reactivate it, contact GoProperty.',
                actionLabel: 'See your subscription',
            )),
            self::text(DunningStep::Suspended, 'GoProperty subscription suspended (SMS)', 'By SMS to each owner with a verified phone when an unpaid subscription is suspended. Paid by GoProperty: no SMS credit is taken.', [
                new Placeholder('organization.name', "The organization's name (cut to 40 characters)", 'Adeyemi Realty Ltd', 40),
                new Placeholder('amount.overdue', 'What is overdue, e.g. "NGN 43,000"', 'NGN 43,000', 16),
                new Placeholder('closing.date', 'The day it is cancelled if unpaid, e.g. "7 Mar 2027"', '7 Mar 2027', 11),
            ], 'GoProperty: {{ organization.name }} is suspended, {{ amount.overdue }} unpaid. An owner can pay in Settings > Subscription to restore it.'),
            self::text(DunningStep::SuspensionReminder, 'GoProperty subscription still suspended (SMS)', 'By SMS to each owner with a verified phone every 14 days while suspended. Paid by GoProperty: no SMS credit is taken.', [
                new Placeholder('organization.name', "The organization's name (cut to 40 characters)", 'Adeyemi Realty Ltd', 40),
                new Placeholder('amount.overdue', 'What is overdue, e.g. "NGN 43,000"', 'NGN 43,000', 16),
                new Placeholder('closing.date', 'The day it is cancelled if unpaid, e.g. "7 Mar 2027"', '7 Mar 2027', 11),
            ], 'GoProperty: {{ organization.name }} is still suspended, {{ amount.overdue }} unpaid. Pay in Settings > Subscription by {{ closing.date }}.'),
        ];
    }

    /** @param  list<Placeholder>  $placeholders */
    private static function email(DunningStep $step, string $name, string $description, array $placeholders, TemplateContent $content): TemplateDefinition
    {
        return new TemplateDefinition(
            key: $step->template(),
            name: $name,
            description: "To an organization's owners (and its payer's): ".$description,
            category: TemplateCategory::Transactional,
            placeholders: $placeholders,
            content: $content,
        );
    }

    /** @param  list<Placeholder>  $placeholders */
    private static function text(DunningStep $step, string $name, string $description, array $placeholders, string $body): TemplateDefinition
    {
        return new TemplateDefinition(
            key: (string) $step->smsTemplate(),
            name: $name,
            description: $description,
            category: TemplateCategory::Transactional,
            placeholders: $placeholders,
            content: new TemplateContent('', $body),
            channel: TemplateChannel::Sms,
        );
    }
}
