<?php

declare(strict_types=1);

namespace Modules\Payments\Notices;

use App\Mail\Templates\Placeholder;
use App\Notifications\Channels\WhatsAppEvent;

/**
 * The payment transitions the tenant is told about (P03-10b;
 * ARCHITECTURE.md § 6.4 "notifications on every transition"), each once
 * (`payment_notices`). The wording is plain text built from plain values;
 * nothing here is a template an agency edits (P05 maps WhatsApp events to
 * the agency's approved templates, ARCHITECTURE.md § 6.7).
 *
 * Values: organization, amount, paid_on, method, and per event
 * receipt_number + verify_url (confirmed, voided) or reason (rejected,
 * voided).
 */
enum PaymentNoticeEvent: string
{
    case Recorded = 'payment.recorded';
    case Confirmed = 'payment.confirmed';
    case Rejected = 'payment.rejected';
    case ReceiptVoided = 'receipt.voided';

    /** The event name a WhatsApp message carries (ARCHITECTURE.md § 6.7 event mapping). */
    public function whatsAppEvent(): string
    {
        return match ($this) {
            self::Recorded => 'payment_recorded',
            self::Confirmed => 'receipt_issued',
            self::Rejected => 'payment_rejected',
            self::ReceiptVoided => 'receipt_voided',
        };
    }

    /**
     * What the agency's WhatsApp settings offer for this notice (P05-06):
     * the values TenantNotices fills in. Never SMS (P03 decision 3).
     */
    public function whatsAppDefinition(): WhatsAppEvent
    {
        $agency = new Placeholder('agency.name', "The agency's name", 'Adeyemi Realty');
        $amount = new Placeholder('payment.amount', 'The amount paid', '₦1,500,000');
        $receipt = new Placeholder('receipt.number', "The receipt's number", 'RCT-AR-000123');
        $reason = new Placeholder('reason', 'Why (the rejection or void reason)', 'The transfer was not found on the bank statement');

        return new WhatsAppEvent(
            key: $this->whatsAppEvent(),
            group: 'Payments',
            label: match ($this) {
                self::Recorded => 'Payment recorded (waiting for confirmation)',
                self::Confirmed => 'Payment confirmed: receipt issued',
                self::Rejected => 'Payment could not be confirmed',
                self::ReceiptVoided => 'Receipt voided',
            },
            placeholders: match ($this) {
                self::Recorded => [$agency, $amount],
                self::Confirmed => [$agency, $amount, $receipt],
                self::Rejected => [$agency, $amount, $reason],
                self::ReceiptVoided => [$agency, $amount, $receipt, $reason],
            },
            order: 30,
        );
    }

    /** @param  array<string, string>  $values */
    public function subject(array $values): string
    {
        return match ($this) {
            self::Recorded => "{$values['organization']} recorded your payment of {$values['amount']}",
            self::Confirmed => "Receipt {$values['receipt_number']} for your payment of {$values['amount']}",
            self::Rejected => "{$values['organization']} could not confirm your payment of {$values['amount']}",
            self::ReceiptVoided => "Receipt {$values['receipt_number']} was voided",
        };
    }

    /**
     * The message, one paragraph a line.
     *
     * @param  array<string, string>  $values
     * @return list<string>
     */
    public function lines(array $values): array
    {
        $payment = "your {$values['method']} payment of {$values['amount']} (paid on {$values['paid_on']})";

        return match ($this) {
            self::Recorded => [
                "{$values['organization']} recorded {$payment}.",
                'It is waiting for confirmation. You will get a receipt once it is confirmed.',
            ],
            self::Confirmed => [
                "{$values['organization']} confirmed {$payment}.",
                "Your receipt number is {$values['receipt_number']}. Anyone can check that it is genuine at {$values['verify_url']}.",
            ],
            self::Rejected => [
                "{$values['organization']} could not confirm {$payment}.",
                "Reason: {$values['reason']}",
                "Contact {$values['organization']} if you think this is a mistake.",
            ],
            self::ReceiptVoided => [
                "{$values['organization']} voided receipt {$values['receipt_number']} for {$values['amount']}.",
                "Reason: {$values['reason']}",
                "The receipt is no longer valid; {$values['verify_url']} now shows it as voided. Contact {$values['organization']} if you have questions.",
            ],
        };
    }

    /** @param  array<string, string>  $values */
    public function text(array $values): string
    {
        return implode("\n", $this->lines($values));
    }
}
