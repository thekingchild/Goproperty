<?php

declare(strict_types=1);

namespace Modules\Payments\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Payments\Notices\PaymentNoticeEvent;

/**
 * What a tenant is told about their payment (P03-10b): recorded,
 * confirmed (the receipt's number and verify address), rejected (the
 * reason), receipt voided. In the app for a tenant with a portal account
 * and by email; built from plain values by Notices\TenantNotices, which
 * makes sure each transition is sent once. Never SMS (P03 decision 3).
 * Queued on `notifications`, after the commit.
 */
final class TenantPaymentNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, string>  $values  organization, amount, paid_on, method, receipt_number, verify_url, reason
     * @param  list<string>  $channels  `database` and/or `mail`
     */
    public function __construct(
        public readonly PaymentNoticeEvent $event,
        public readonly int $paymentId,
        public readonly array $values,
        public readonly array $channels,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->event->subject($this->values));

        foreach ($this->event->lines($this->values) as $line) {
            $mail->line($line);
        }

        if ($this->values['verify_url'] !== '') {
            $mail->action('Check the receipt', $this->values['verify_url']);
        }

        return $mail;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event->value,
            'payment_id' => $this->paymentId,
            'organization' => $this->values['organization'],
            'amount' => $this->values['amount'],
            'receipt_number' => $this->values['receipt_number'] !== '' ? $this->values['receipt_number'] : null,
            'reason' => $this->values['reason'] !== '' ? $this->values['reason'] : null,
            'message' => $this->event->subject($this->values),
            'url' => $this->values['verify_url'] !== '' ? $this->values['verify_url'] : null,
        ];
    }
}
